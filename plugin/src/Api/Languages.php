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
 * Joomla content languages → Supertext language codes (the SDK's rules, the same in every
 * Supertext plugin).
 *
 * Supertext accepts only the codes of its `GET features` list (Sdk\V1\Languages::TARGETS):
 * regional for German, English, French, Italian and Portuguese (de-CH, en-US, pt-BR), with a
 * script for Chinese and Serbian (zh-Hans, sr-Latn), a dialect for Swiss German
 * (gsw-u-sd-chzh), bare for all others (es, nl, sl). A content language is sent as:
 *
 * 1. the Supertext language chosen for it on the plugin's Languages tab (the mapping), else
 * 2. its own tag, if that is a Supertext code (de-CH, fr-FR; case and _/- ignored), else
 * 3. the SDK's default where one is obvious (de → de-DE, es-ES → es, zh-CN → zh-Hans), else
 * 4. nothing: unsupported_language, and the administrator has to choose one (en-IE, gsw).
 *
 * The source is the same mapping's code in its source form (de-CH → de), else the tag's
 * language (en-GB → en). No Joomla dependency.
 */
final class Languages
{
    private const TONES = ['default', 'more', 'less'];

    /**
     * The saved Languages tab as tag → {code, politeness, review}. Reads the current format (keyed by
     * content language tag) and the repeatable rows of plugin versions up to 0.1.0
     * ({"languages0": {"language": "es-ES", "code": "es", ...}}), so an upgrade loses nothing:
     * the next save writes the current format. `review` (Translation review) is off unless saved on.
     *
     * @return array<string, array{code: string, politeness: string, review: bool}>
     */
    public static function settings(mixed $stored): array
    {
        if (\is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (\is_object($stored)) {
            $stored = json_decode((string) json_encode($stored), true);
        }

        $settings = [];

        foreach (\is_array($stored) ? $stored : [] as $key => $row) {
            if (!\is_array($row)) {
                continue;
            }

            $tag = trim((string) (isset($row['language']) ? $row['language'] : $key));

            if ($tag === '') {
                continue;
            }

            $settings[$tag] = [
                'code'       => trim((string) ($row['code'] ?? '')),
                'politeness' => \in_array($row['politeness'] ?? null, self::TONES, true) ? (string) $row['politeness'] : 'default',
                'review'     => \in_array($row['review'] ?? false, [true, 1, '1', 'true', 'on'], true),
            ];
        }

        return $settings;
    }

    /**
     * The Languages tab as posted, validated for saving: tags that look like language tags,
     * codes only from Supertext's list (written as the list writes them, else ""), a formal or
     * informal tone only where the chosen code offers it, Translation review on or off.
     *
     * @return array<string, array{code: string, politeness: string, review: bool}>
     */
    public static function clean(mixed $posted): array
    {
        $clean = [];

        foreach (self::settings($posted) as $tag => $row) {
            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $tag) !== 1) {
                continue;
            }

            $code       = $row['code'] !== '' ? (SdkLanguages::matchTarget($row['code']) ?? '') : '';
            $politeness = $code !== '' && SdkLanguages::supportsPoliteness($code) === true ? $row['politeness'] : 'default';

            $clean[$tag] = ['code' => $code, 'politeness' => $politeness, 'review' => $row['review']];
        }

        return $clean;
    }

    /** What the Languages tab preselects for a content language without a saved choice: the exact Supertext code, else the default, else null. */
    public static function suggest(string $tag): ?string
    {
        return SdkLanguages::suggestTarget($tag);
    }

    /**
     * The mapping from the plugin's Languages tab: content language tag → Supertext code (rows without a code are left out).
     *
     * @param array<string, array{code?: string, politeness?: string, review?: bool}> $settings keyed by Joomla language tag
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
     * @throws SupertextException unsupported_language when it is not mapped, not a Supertext code and has no default
     */
    public static function target(string $tag, array $mapping = []): string
    {
        try {
            return SdkLanguages::target($tag, $mapping);
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** The target code, or null when the language has neither a mapping, an exact code nor a default. */
    public static function targetOrNull(string $tag, array $mapping = []): ?string
    {
        try {
            return SdkLanguages::target($tag, $mapping);
        } catch (SdkException) {
            return null;
        }
    }

    /**
     * The source code to send for the article's language: the mapping in its source form (one
     * mapping for both directions: de-CH → de), else the tag's language (en-GB → en). "" stays ""
     * (auto-detect).
     *
     * @param array<string, string> $mapping
     *
     * @throws SupertextException unsupported_language when Supertext has no such source language
     */
    public static function source(string $tag, array $mapping = []): string
    {
        try {
            return SdkLanguages::source($tag, $mapping);
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

    /** @return list<array{code: string, name: string, politeness: bool}> Supertext's target languages sorted by English name, for the Languages tab's lists */
    public static function targets(): array
    {
        return SdkLanguages::targetOptions();
    }
}
