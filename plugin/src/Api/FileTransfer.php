<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

use Supertext\Plugin\System\Supertext\Sdk\Files\FileJob;
use Supertext\Plugin\System\Supertext\Sdk\Files\FileSegment;
use Supertext\Plugin\System\Supertext\Sdk\Files\ImportedSegment;
use Supertext\Plugin\System\Supertext\Sdk\Files\TranslationFileError;
use Supertext\Plugin\System\Supertext\Sdk\Files\TranslationFiles;

/**
 * "Export for translation" / "Import translation": an article's segments as an XLIFF 1.2 or
 * HTML file (the SDK's TranslationFiles), and the translated file written back like an API
 * translation. No Joomla dependency: the plugin passes in the article's segments (the same
 * collector as for API translations) and the write-back as closures.
 *
 * Reference of a job: `article:<source article id>:<Joomla language tag of the target>`,
 * e.g. `article:12:fr-FR`.
 */
final class FileTransfer
{
    /** The `plugin` of every job: this repository's name. */
    public const PLUGIN = 'Joomla-Supertext-Translation';

    /** Uploads larger than this are refused before they are read. */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const XLIFF = 'xliff';
    public const HTML  = 'html';

    /**
     * @param array<string, string> $mapping content language tag → Supertext code (the Languages tab)
     */
    public function __construct(private readonly array $mapping = [])
    {
    }

    public static function reference(int $articleId, string $tag): string
    {
        return 'article:' . $articleId . ':' . $tag;
    }

    /** @return array{id: int, tag: string}|null */
    public static function parseReference(string $reference): ?array
    {
        if (!preg_match('/^article:([1-9]\d{0,18}):([A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*)$/', $reference, $m)) {
            return null;
        }

        return ['id' => (int) $m[1], 'tag' => $m[2]];
    }

    /** `joomla-12-fr-FR.xlf`, `joomla-12.xlf` for several languages, `joomla-12-fr-FR.html`. */
    public static function fileName(int $articleId, array $targets, string $format): string
    {
        $suffix = \count($targets) === 1 ? '-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) reset($targets)) : '';

        return 'joomla-' . $articleId . $suffix . ($format === self::HTML ? '.html' : '.xlf');
    }

    /** The Supertext source code of a content language; the tag itself (with `-`) where Supertext has none. */
    public function sourceCode(string $tag): string
    {
        try {
            return Languages::source($tag, $this->mapping);
        } catch (\Throwable) {
            return str_replace('_', '-', $tag);
        }
    }

    /** The Supertext target code of a content language; the tag itself (with `-`) where it isn't mapped. */
    public function targetCode(string $tag): string
    {
        return Languages::targetOrNull($tag, $this->mapping) ?? str_replace('_', '-', $tag);
    }

    /**
     * Only the segments the API translation would send (blank ones are left out the same way).
     *
     * @param array<string, array{0: string, 1: bool}> $fields key => [text, isHtml]
     *
     * @return list<FileSegment>
     */
    public static function segments(array $fields): array
    {
        $segments = [];

        foreach ($fields as $key => [$text, $isHtml]) {
            if (trim(strip_tags($text)) !== '') {
                $segments[] = new FileSegment((string) $key, $text, $isHtml);
            }
        }

        return $segments;
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $fields  the article's segments (ArticleTranslator's collector)
     * @param list<string>                             $targets Joomla language tags; exactly one for HTML
     *
     * @return array{name: string, type: string, body: string}
     *
     * @throws FileTransferException nothing_to_translate, invalid_request
     */
    public function export(int $articleId, string $sourceTag, array $fields, array $targets, string $format): array
    {
        $targets  = array_values(array_unique($targets));
        $segments = self::segments($fields);

        if (!$targets || ($format === self::HTML && \count($targets) !== 1) || !\in_array($format, [self::XLIFF, self::HTML], true)) {
            throw new FileTransferException('invalid_request');
        }

        if (!$segments) {
            throw new FileTransferException('nothing_to_translate');
        }

        $jobs = [];

        foreach ($targets as $tag) {
            $jobs[] = new FileJob(self::PLUGIN, self::reference($articleId, $tag), $this->sourceCode($sourceTag), $this->targetCode($tag), $segments);
        }

        try {
            $body = $format === self::HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        } catch (TranslationFileError $e) {
            throw new FileTransferException($e->errorCode === TranslationFileError::INVALID_JOB ? 'nothing_to_translate' : $e->errorCode);
        }

        return [
            'name' => self::fileName($articleId, $targets, $format),
            'type' => $format === self::HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml',
            'body' => $body,
        ];
    }

    /** @throws FileTransferException too_large */
    public static function assertUploadSize(int $bytes): void
    {
        if ($bytes > self::MAX_UPLOAD_BYTES) {
            throw new FileTransferException('too_large');
        }
    }

    /**
     * Reads an uploaded file and writes every job back.
     *
     * @param \Closure(int, string): array<string, array{0: string, 1: bool}> $current the article's current segments for
     *        that target language; throws FileTransferException not_found / no_language (or any error, e.g. no permission)
     * @param \Closure(int, string, array<string, string>): array<string, mixed> $write writes the translations (key => text)
     *        into the target language like an API translation; returns e.g. {status: created|updated|skipped, id, title}
     *
     * @return list<array{reference: string, article: int, language: string, counts: array{imported: int, changed: int, untranslated: int, invalid: int, missing: int}, saved?: array<string, mixed>, error?: FileTransferException|\Throwable}>
     *
     * @throws FileTransferException invalid_file, missing_metadata, too_large (the whole file)
     */
    public function import(string $content, bool $includeChanged, \Closure $current, \Closure $write): array
    {
        self::assertUploadSize(\strlen($content));

        try {
            $files = TranslationFiles::read($content);
        } catch (TranslationFileError $e) {
            throw new FileTransferException($e->errorCode);
        }

        $results = [];

        foreach ($files as $file) {
            $ref    = self::parseReference($file->reference);
            $result = [
                'reference' => $file->reference,
                'article'   => $ref['id'] ?? 0,
                'language'  => $ref['tag'] ?? '',
                'counts'    => ['imported' => 0, 'changed' => 0, 'untranslated' => 0, 'invalid' => 0, 'missing' => 0],
            ];

            try {
                if ($file->plugin !== self::PLUGIN) {
                    throw new FileTransferException('other_system', [$file->plugin]);
                }

                if ($ref === null) {
                    throw new FileTransferException('not_found');
                }

                $segments     = self::segments($current($ref['id'], $ref['tag']));
                $translations = [];

                foreach (TranslationFiles::importTranslations($file, $segments) as $segment) {
                    $use = $segment->status === ImportedSegment::OK
                        || ($segment->status === ImportedSegment::SOURCE_CHANGED && $includeChanged);

                    if ($use && $segment->text !== null && trim(strip_tags($segment->text)) !== '') {
                        $translations[$segment->key] = $segment->text;
                        continue;
                    }

                    $bucket = match ($segment->status) {
                        ImportedSegment::SOURCE_CHANGED => 'changed',
                        ImportedSegment::INVALID_MARKUP => 'invalid',
                        ImportedSegment::MISSING        => 'missing',
                        default                         => 'untranslated',
                    };
                    $result['counts'][$bucket]++;
                }

                if ($translations) {
                    $result['saved'] = $write($ref['id'], $ref['tag'], $translations);

                    if (($result['saved']['status'] ?? '') !== 'skipped') {
                        $result['counts']['imported'] = \count($translations);
                    }
                }
            } catch (\Throwable $e) {
                $result['error'] = $e;
            }

            $results[] = $result;
        }

        return $results;
    }
}
