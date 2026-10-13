<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

/**
 * Joomla-specific additions to the Supertext SDK's HtmlDocument (which packs the fields
 * into the document): keeps Joomla's plugin tags ({loadposition …}) out of translation.
 */
final class HtmlDocument
{
    /**
     * Joomla plugin tags such as {loadmodule mod_menu} or {loadposition sidebar} are code:
     * wrap them so the translator leaves them alone. {@see unprotect()} removes the wrapper.
     */
    public static function protect(string $html): string
    {
        return (string) preg_replace('/\{[a-z][\w-]*(?:[\s=][^{}]*)?\}|\{\/[a-z][\w-]*\}/i', '<span translate="no" class="notranslate" data-st-keep>$0</span>', $html);
    }

    public static function unprotect(string $html): string
    {
        return (string) preg_replace('#<span(?=[^>]*\bdata-st-keep\b)[^>]*>(.*?)</span>#s', '$1', $html);
    }
}
