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
use Supertext\Plugin\System\Supertext\Api\Reporting;
use Supertext\Plugin\System\Supertext\Extension\Supertext;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Below "Send version information to Supertext": what it sends (always shown, Joomla hides
 * field descriptions unless inline help is on), whether SUPERTEXT_VERSION_REPORTS overrides
 * the setting on this server, and the ping address Supertext checks while reports are on.
 */
final class ReportingField extends FormField
{
    protected $type = 'Reporting';

    protected function getInput()
    {
        $plugin = Factory::getApplication()->bootPlugin('supertext', 'system');

        if (!$plugin instanceof Supertext) {
            return '';
        }

        $html     = '<p class="small text-muted mb-1">' . Text::_('PLG_SYSTEM_SUPERTEXT_VERSION_REPORTS_DESC') . '</p>';
        $override = Reporting::environmentOverride();

        if ($override !== null) {
            $html .= '<p class="small mb-1">' . Text::sprintf(
                $plugin->reportsEnabled() ? 'PLG_SYSTEM_SUPERTEXT_VERSION_REPORTS_ENV_ON' : 'PLG_SYSTEM_SUPERTEXT_VERSION_REPORTS_ENV_OFF',
                htmlspecialchars($override, ENT_QUOTES, 'UTF-8')
            ) . '</p>';
        }

        $ping = $plugin->pingUrl();

        if ($plugin->reportsEnabled() && $ping !== null) {
            $html .= '<p class="small text-muted mb-0">' . Text::sprintf('PLG_SYSTEM_SUPERTEXT_VERSION_REPORTS_PING', '<code>' . htmlspecialchars($ping, ENT_QUOTES, 'UTF-8') . '</code>') . '</p>';
        }

        return '<div class="supertext-reporting">' . $html . '</div>';
    }
}
