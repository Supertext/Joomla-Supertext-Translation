<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Supertext\Plugin\System\Supertext\Extension\Supertext;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The Languages tab's overview: every content language with the Supertext code it is sent as
 * (from the saved settings), "Not mapped" for those that still need a row below, and a warning
 * for a tone Supertext doesn't offer for that language.
 */
final class LanguagestatusField extends FormField
{
    protected $type = 'Languagestatus';

    protected function getInput()
    {
        $plugin = Factory::getApplication()->bootPlugin('supertext', 'system');

        if (!$plugin instanceof Supertext) {
            return '';
        }

        $rows = $plugin->languageOverview();

        if (!$rows) {
            return '<p class="text-muted">' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_STATUS_NONE') . '</p>';
        }

        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<table class="table table-sm supertext-languages"><thead><tr>'
            . '<th scope="col">' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_STATUS_LANGUAGE') . '</th>'
            . '<th scope="col">' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_STATUS_CODE') . '</th>'
            . '<th scope="col">' . Text::_('PLG_SYSTEM_SUPERTEXT_POLITENESS') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $code = $row['target'] !== null
                ? '<code>' . $e($row['target']) . '</code>'
                : '<span class="text-danger"><span class="icon-warning" aria-hidden="true"></span> ' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_NOT_MAPPED') . '</span>';
            $tone = Text::_('PLG_SYSTEM_SUPERTEXT_POLITENESS_' . strtoupper(\in_array($row['politeness'], ['more', 'less'], true) ? $row['politeness'] : 'default'));

            if ($row['politeness'] !== 'default' && $row['politenessSupported'] === false) {
                $tone .= ' <span class="text-danger d-block small"><span class="icon-warning" aria-hidden="true"></span> ' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_NO_POLITENESS') . '</span>';
            }

            $html .= '<tr><td>' . $e($row['title']) . ' <code>' . $e($row['tag']) . '</code></td><td>' . $code . '</td><td>' . $tone . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }
}
