<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Language;
use Supertext\Plugin\System\Supertext\Api\FileTransferException;
use Supertext\Plugin\System\Supertext\Api\SupertextException;
use Supertext\Plugin\System\Supertext\PluginHealth\KeyCheck;

/** Turns an exception or an API key check into a message in the user's back-end language. */
final class Messages
{
    /**
     * The key check result (texts from the Commando Center's guide): PLG_SYSTEM_SUPERTEXT_KEYCHECK_<STATUS>.
     * HTML (links to supertext.com in a new tab), from the plugin's language files.
     */
    public static function keyCheck(KeyCheck $check, Language $language): string
    {
        $key = 'PLG_SYSTEM_SUPERTEXT_KEYCHECK_' . strtoupper($check->status);

        if (!$language->hasKey($key)) {
            $key = 'PLG_SYSTEM_SUPERTEXT_KEYCHECK_ERROR';
        }

        return sprintf($language->_($key), (string) ($check->httpStatus ?? '–'));
    }

    public static function of(\Throwable $e, Language $language): string
    {
        if ($e instanceof FileTransferException) {
            $key = 'PLG_SYSTEM_SUPERTEXT_FILE_' . strtoupper($e->reason);

            return $language->hasKey($key) ? sprintf($language->_($key), ...$e->args) : $e->getMessage();
        }

        if (!$e instanceof SupertextException || $e->reason === '') {
            return $e->getMessage();
        }

        $key = 'PLG_SYSTEM_SUPERTEXT_API_' . strtoupper($e->reason);

        if (!$language->hasKey($key)) {
            return $e->getMessage();
        }

        $text = $e->args ? sprintf($language->_($key), ...$e->args) : $language->_($key);

        return $e->detail !== '' ? $text . ' (' . $e->detail . ')' : $text;
    }
}
