<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

use Supertext\Plugin\System\Supertext\Sdk\LanguageCodes as SdkLanguageCodes;

/**
 * CMS locales → Supertext language codes.
 *
 * Supertext wants the source as a bare language ("en") and the target with a
 * region ("de-DE", "en-US"); a bare target such as "de" is rejected with
 * INVALID_LANGUAGE_PAIR. Locales without a region get the default region
 * from the Supertext SDK (same table in every Supertext plugin); unknown bare
 * codes are sent unchanged, so Supertext's error names them. The site's own
 * override (the plugin's Supertext language code per content language) wins over the language.
 */
final class LanguageCodes
{
    /** The region Supertext uses when a locale has none (the SDK's table). */
    public const DEFAULT_REGIONS = SdkLanguageCodes::DEFAULT_REGIONS;

    /** "de_ch" → "de-CH", "DE" → "de", "zh_hant" → "zh-Hant", "es-419" stays. */
    public static function normalize(string $code): string
    {
        return SdkLanguageCodes::normalize($code);
    }

    /** Source code to send: the bare language of the override, else of the locale ("en-GB" → "en"). */
    public static function source(string $locale, string $override = ''): string
    {
        return SdkLanguageCodes::source(trim($override) !== '' ? $override : $locale);
    }

    /** Target code to send: the override, else the locale; without a region it gets the default one ("de" → "de-DE"). */
    public static function target(string $locale, string $override = ''): string
    {
        return SdkLanguageCodes::target(trim($override) !== '' ? $override : $locale);
    }
}
