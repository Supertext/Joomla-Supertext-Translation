<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\TestCase;
use Supertext\Plugin\System\Supertext\Api\FileTransfer;
use Supertext\Plugin\System\Supertext\Api\FileTransferException;
use Supertext\Plugin\System\Supertext\Sdk\Files\TranslationFiles;

/** "Export for translation" / "Import translation": the glue around the SDK's TranslationFiles. */
final class FileTransferTest extends TestCase
{
    /** @var array<string, array{0: string, 1: bool}> what ArticleTranslator collects for an article */
    private const FIELDS = [
        'article.title'     => ['Welcome', false],
        'article.introtext' => ['<p>Hello <strong>world</strong></p>', true],
        'article.fulltext'  => ['', true],
        'images.image_intro_alt' => ['A lake', false],
    ];

    public function testReferenceRoundTrip(): void
    {
        self::assertSame('article:12:fr-FR', FileTransfer::reference(12, 'fr-FR'));
        self::assertSame(['id' => 12, 'tag' => 'fr-FR'], FileTransfer::parseReference('article:12:fr-FR'));
        self::assertSame(['id' => 7, 'tag' => 'sr-Latn-RS'], FileTransfer::parseReference(FileTransfer::reference(7, 'sr-Latn-RS')));

        foreach (['', 'article:0:fr-FR', 'article:12', 'page:12:fr-FR', 'article:12:fr FR', 'article:x:fr-FR'] as $bad) {
            self::assertNull(FileTransfer::parseReference($bad), $bad);
        }
    }

    public function testFileNames(): void
    {
        self::assertSame('joomla-12-fr-FR.xlf', FileTransfer::fileName(12, ['fr-FR'], FileTransfer::XLIFF));
        self::assertSame('joomla-12.xlf', FileTransfer::fileName(12, ['fr-FR', 'it-IT'], FileTransfer::XLIFF));
        self::assertSame('joomla-12-fr-FR.html', FileTransfer::fileName(12, ['fr-FR'], FileTransfer::HTML));
    }

    public function testXliffExportUsesTheCollectorsKeysAndTheMapping(): void
    {
        $file = (new FileTransfer(['fr-FR' => 'fr-CH']))->export(12, 'de-CH', self::FIELDS, ['fr-FR', 'en-IE'], FileTransfer::XLIFF);

        self::assertSame('joomla-12.xlf', $file['name']);
        self::assertSame('application/x-xliff+xml', $file['type']);

        $jobs = TranslationFiles::read($file['body']);
        self::assertCount(2, $jobs);
        self::assertSame(FileTransfer::PLUGIN, $jobs[0]->plugin);
        self::assertSame('article:12:fr-FR', $jobs[0]->reference);
        self::assertSame('de', $jobs[0]->sourceLanguage);
        self::assertSame('fr-CH', $jobs[0]->targetLanguage, 'the mapping wins');
        self::assertSame('en-IE', $jobs[1]->targetLanguage, 'an unmapped language is sent as its tag');
        self::assertSame(['article.title', 'article.introtext', 'images.image_intro_alt'], array_map(static fn ($u) => $u->key, $jobs[0]->units));
    }

    public function testHtmlExportHasExactlyOneLanguage(): void
    {
        $file = (new FileTransfer())->export(3, 'en-GB', self::FIELDS, ['de-DE'], FileTransfer::HTML);

        self::assertSame('joomla-3-de-DE.html', $file['name']);
        self::assertSame('text/html; charset=utf-8', $file['type']);
        self::assertSame('de-DE', TranslationFiles::read($file['body'])[0]->targetLanguage);

        $this->expectExceptionObject(new FileTransferException('invalid_request'));
        (new FileTransfer())->export(3, 'en-GB', self::FIELDS, ['de-DE', 'fr-FR'], FileTransfer::HTML);
    }

    public function testNothingToTranslate(): void
    {
        try {
            (new FileTransfer())->export(3, 'en-GB', ['article.title' => [' ', false], 'article.introtext' => ['<p> </p>', true]], ['de-DE'], FileTransfer::XLIFF);
            self::fail('no exception');
        } catch (FileTransferException $e) {
            self::assertSame('nothing_to_translate', $e->reason);
        }
    }

    public function testImportWritesOkSegmentsOnly(): void
    {
        $xliff = $this->translated($this->export(), [
            'Welcome'       => 'Bienvenue',
            'A lake'        => 'Un lac',
        ]);
        $written = [];
        $results = $this->import($xliff, false, self::FIELDS, $written);

        self::assertSame([[12, 'fr-FR', ['article.title' => 'Bienvenue', 'images.image_intro_alt' => 'Un lac']]], $written);
        self::assertSame(['imported' => 2, 'changed' => 0, 'untranslated' => 1, 'invalid' => 0, 'missing' => 0], $results[0]['counts']);
        self::assertSame('created', $results[0]['saved']['status']);
        self::assertArrayNotHasKey('error', $results[0]);
    }

    public function testSourceChangedOnlyWithTheOption(): void
    {
        $xliff   = $this->translated($this->export(), ['Welcome' => 'Bienvenue', 'A lake' => 'Un lac']);
        $changed = ['article.title' => ['Welcome home', false]] + self::FIELDS;

        $written = [];
        $results = $this->import($xliff, false, $changed, $written);
        self::assertSame(['images.image_intro_alt' => 'Un lac'], $written[0][2]);
        self::assertSame(1, $results[0]['counts']['changed']);
        self::assertSame(1, $results[0]['counts']['imported']);

        $written = [];
        $results = $this->import($xliff, true, $changed, $written);
        self::assertSame(['article.title' => 'Bienvenue', 'images.image_intro_alt' => 'Un lac'], $written[0][2]);
        self::assertSame(0, $results[0]['counts']['changed']);
        self::assertSame(2, $results[0]['counts']['imported']);
    }

    public function testInvalidMarkupAndMissingAreNotWritten(): void
    {
        $html = (new FileTransfer())->export(12, 'en-GB', self::FIELDS, ['fr-FR'], FileTransfer::HTML)['body'];
        $html = str_replace(['Hello <strong>world</strong>', '>Welcome<'], ['Bonjour <script>x</script>', '>Bienvenue<'], $html);
        $more = self::FIELDS + ['field.subtitle' => ['A new field', false]];

        $written = [];
        $results = $this->import($html, false, $more, $written);

        self::assertArrayHasKey('article.title', $written[0][2]);
        self::assertArrayNotHasKey('article.introtext', $written[0][2]);
        self::assertArrayNotHasKey('field.subtitle', $written[0][2]);
        self::assertSame(1, $results[0]['counts']['invalid']);
        self::assertSame(1, $results[0]['counts']['missing']);
    }

    public function testNothingWrittenWhenNothingIsTranslated(): void
    {
        $written = [];
        $results = $this->import($this->export(), false, self::FIELDS, $written);

        self::assertSame([], $written);
        self::assertSame(3, $results[0]['counts']['untranslated']);
        self::assertArrayNotHasKey('saved', $results[0]);
    }

    public function testFileOfAnotherPlugin(): void
    {
        $body    = str_replace(FileTransfer::PLUGIN, 'Contao-Supertext-Translation', $this->export());
        $written = [];
        $results = $this->import($body, false, self::FIELDS, $written);

        self::assertSame([], $written);
        self::assertInstanceOf(FileTransferException::class, $results[0]['error']);
        self::assertSame('other_system', $results[0]['error']->reason);
        self::assertSame(['Contao-Supertext-Translation'], $results[0]['error']->args);
    }

    public function testUnknownReference(): void
    {
        $body    = str_replace('article:12:fr-FR', 'page:12:fr-FR', $this->export());
        $written = [];
        $results = $this->import($body, false, self::FIELDS, $written);

        self::assertSame('not_found', $results[0]['error']->reason);
        self::assertSame([], $written);
    }

    public function testMissingArticleOrLanguageComesFromTheLoader(): void
    {
        $results = (new FileTransfer())->import(
            $this->export(),
            false,
            static fn (int $id, string $tag) => throw new FileTransferException('no_language', [$tag]),
            static fn () => self::fail('nothing may be written'),
        );

        self::assertSame('no_language', $results[0]['error']->reason);
        self::assertSame(['fr-FR'], $results[0]['error']->args);
    }

    public function testSkippedWriteImportsNothing(): void
    {
        $xliff   = $this->translated($this->export(), ['Welcome' => 'Bienvenue']);
        $results = (new FileTransfer())->import($xliff, false, static fn () => self::FIELDS, static fn () => ['status' => 'skipped', 'id' => 5, 'title' => 'Bienvenue']);

        self::assertSame('skipped', $results[0]['saved']['status']);
        self::assertSame(0, $results[0]['counts']['imported']);
    }

    public function testFileErrors(): void
    {
        foreach (['<html><body>hi</body></html>' => 'missing_metadata', 'not a file' => 'invalid_file', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><xliff/>' => 'invalid_file'] as $content => $reason) {
            try {
                (new FileTransfer())->import($content, false, static fn () => [], static fn () => []);
                self::fail('no exception for ' . $reason);
            } catch (FileTransferException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testTooLargeIsRefused(): void
    {
        FileTransfer::assertUploadSize(FileTransfer::MAX_UPLOAD_BYTES);

        try {
            FileTransfer::assertUploadSize(FileTransfer::MAX_UPLOAD_BYTES + 1);
            self::fail('no exception');
        } catch (FileTransferException $e) {
            self::assertSame('too_large', $e->reason);
        }

        $this->expectExceptionObject(new FileTransferException('too_large'));
        (new FileTransfer())->import(str_repeat(' ', FileTransfer::MAX_UPLOAD_BYTES + 1), false, static fn () => [], static fn () => []);
    }

    private function export(): string
    {
        return (new FileTransfer())->export(12, 'en-GB', self::FIELDS, ['fr-FR'], FileTransfer::XLIFF)['body'];
    }

    /**
     * Fills <target> elements the way a CAT tool does, for the given plain sources.
     *
     * @param array<string, string> $translations source text => translation
     */
    private function translated(string $xliff, array $translations): string
    {
        foreach ($translations as $source => $target) {
            $xliff = preg_replace('#(<source>' . preg_quote($source, '#') . '</source>)(\s*<target[^>]*>)?[^<]*(</target>)?#', '$1<target state="translated">' . $target . '</target>', $xliff, 1, $count);
            self::assertSame(1, $count, $source);
        }

        return (string) $xliff;
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $current
     * @param list<array{0: int, 1: string, 2: array<string, string>}> $written
     *
     * @return list<array<string, mixed>>
     */
    private function import(string $content, bool $includeChanged, array $current, array &$written): array
    {
        return (new FileTransfer())->import(
            $content,
            $includeChanged,
            static fn (int $id, string $tag): array => $current,
            static function (int $id, string $tag, array $translations) use (&$written): array {
                $written[] = [$id, $tag, $translations];

                return ['status' => 'created', 'id' => 99, 'title' => $translations['article.title'] ?? ''];
            },
        );
    }
}
