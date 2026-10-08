<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Language;
use Supertext\Plugin\System\Supertext\Api\SupertextException;

/** Turns an exception into a message in the user's back-end language. */
final class Messages
{
    public static function of(\Throwable $e, Language $language): string
    {
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
