<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

use Supertext\Plugin\System\Supertext\Sdk\V1\Languages as SdkLanguages;
use Supertext\Plugin\System\Supertext\Sdk\V1\SupertextException as SdkException;

/**
 * Joomla content languages → Supertext language codes, without guessing (the SDK's rules,
 * the same in every Supertext plugin).
 *
 * Supertext accepts only the codes of its `GET features` list (Sdk\V1\Languages::TARGETS):
 * regional for German, English, French, Italian and Portuguese (de-CH, en-US, pt-BR), with a
 * script for Chinese and Serbian (zh-Hans, sr-Latn), a dialect for Swiss German
 * (gsw-u-sd-chzh), bare for all others (es, nl, sl). A content language is sent as:
 *
 * 1. the Supertext code set for it on the plugin's Languages tab (the mapping), else
 * 2. its own tag, if that is a Supertext code (de-CH, fr-FR; case and _/- ignored), else
 * 3. nothing: unsupported_language, and the administrator has to map it (es-ES → es).
 *
 * The source is the bare language of the mapped code or the tag (en-GB → en). No Joomla
 * dependency.
 */
final class Languages
{
    /**
     * The mapping from the plugin's Languages tab: content language tag → Supertext code (rows without a code are left out).
     *
     * @param array<string, array{code?: string, politeness?: string}> $settings keyed by Joomla language tag
     *
     * @return array<string, string>
     */
    public static function mapping(array $settings): array
    {
        $mapping = [];

        foreach ($settings as $tag => $row) {
            $code = trim((string) ($row['code'] ?? ''));

            if ($code !== '') {
                $mapping[(string) $tag] = $code;
            }
        }

        return $mapping;
    }

    /**
     * The target code to send for a content language.
     *
     * @param array<string, string> $mapping
     *
     * @throws SupertextException unsupported_language when it is neither mapped nor a Supertext code
     */
    public static function target(string $tag, array $mapping = []): string
    {
        try {
            return SdkLanguages::target($tag, $mapping);
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** The target code, or null when the language still needs a mapping (for the settings page). */
    public static function targetOrNull(string $tag, array $mapping = []): ?string
    {
        try {
            return SdkLanguages::target($tag, $mapping);
        } catch (SdkException) {
            return null;
        }
    }

    /**
     * The source code to send for the article's language: the bare language of its mapped
     * Supertext code (de-CH → de), else of the tag (en-GB → en). "" stays "" (auto-detect).
     *
     * @param array<string, string> $mapping
     *
     * @throws SupertextException unsupported_language when Supertext has no such source language
     */
    public static function source(string $tag, array $mapping = []): string
    {
        $mapped = '';

        foreach ($mapping as $from => $to) {
            if (self::key((string) $from) === self::key($tag)) {
                $mapped = $to;
            }
        }

        try {
            return SdkLanguages::source($mapped !== '' && SdkLanguages::matchSource($mapped) !== null ? $mapped : $tag);
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /**
     * The tone to send: "default", "more" or "less". Formal/informal for a target Supertext
     * doesn't offer it for (en-US, rm, sq, sr-*, gsw-*) is refused here, before anything is sent.
     *
     * @throws SupertextException unsupported_feature
     */
    public static function politeness(string $target, string $politeness): string
    {
        if (!\in_array($politeness, ['more', 'less'], true)) {
            return 'default';
        }

        if (SdkLanguages::supportsPoliteness($target) === false) {
            throw SupertextClient::fromSdk(new SdkException(SdkException::UNSUPPORTED_FEATURE, targetLang: $target, feature: 'politeness'));
        }

        return $politeness;
    }

    /** Whether Supertext offers formal/informal for a target code; null for a code that isn't in its list. */
    public static function supportsPoliteness(string $target): ?bool
    {
        return SdkLanguages::supportsPoliteness($target);
    }

    /** The supported target that equals $tag apart from case and _/- ("fr_fr" → "fr-FR"), else null. */
    public static function exactTarget(string $tag): ?string
    {
        return SdkLanguages::matchTarget($tag);
    }

    /** @return list<array{code: string, name: string, politeness: bool}> Supertext's target languages, e.g. for the settings' list */
    public static function targets(): array
    {
        return SdkLanguages::TARGETS;
    }

    private static function key(string $code): string
    {
        return strtolower(str_replace('_', '-', trim($code)));
    }
}
