<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Field;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Supertext\Plugin\System\Supertext\Api\Reporting;
use Supertext\Plugin\System\Supertext\Extension\Supertext;
use Supertext\Plugin\System\Supertext\Sdk\V1\ApiKeyCheck;
use Supertext\Plugin\System\Supertext\Service\Messages;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The API key check in the plugin settings: the latest automatic check (after every save, when
 * the key changed, after Supertext rejected it) with its time, and the "Test connection" button
 * that runs it again with the saved key (cost-free `GET /v1/features`).
 *
 * Opening the settings page is a back-end request that runs the plugin: it also schedules the
 * Commando Center's check (upgrades, a changed key from SUPERTEXT_API_KEY) for after the response.
 */
final class ConnectionField extends FormField
{
    protected $type = 'Connection';

    protected function getInput()
    {
        $endpoint = Uri::base() . 'index.php?option=com_ajax&group=system&plugin=supertext&format=json';
        $token    = Session::getFormToken();
        $env      = getenv('SUPERTEXT_API_KEY');
        $note     = \is_string($env) && $env !== '' ? '<p class="small text-muted mb-2">' . Text::_('PLG_SYSTEM_SUPERTEXT_ENV_KEY_ACTIVE') . '</p>' : '';
        $id       = 'supertext-test-' . uniqid();

        $app    = Factory::getApplication();
        $plugin = $app->bootPlugin('supertext', 'system');
        $check  = null;

        if ($plugin instanceof Supertext) {
            $check = $plugin->lastCheck();
            $plugin->reportLater(Reporting::ON_REQUEST);
        }

        $current = $check
            ? '<span class="' . ($check->status === ApiKeyCheck::OK ? 'text-success' : 'text-danger') . '">' . Messages::keyCheck($check, $app->getLanguage()) . '</span>'
                . ' <span class="small text-muted">' . htmlspecialchars($plugin->checkedAt($check), ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span class="text-muted">' . Text::_('PLG_SYSTEM_SUPERTEXT_KEYCHECK_NOT_CHECKED') . '</span>';

        if ($app instanceof CMSWebApplicationInterface) {
            $app->getDocument()->getWebAssetManager()->addInlineScript(
                <<<JS
                document.addEventListener('DOMContentLoaded', () => {
                  const button = document.getElementById('{$id}');
                  const out = document.getElementById('{$id}-result');
                  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
                  button.addEventListener('click', async () => {
                    button.disabled = true;
                    out.innerHTML = '…';
                    const body = new FormData();
                    body.append('{$token}', '1');
                    body.append('action', 'test');
                    try {
                      const res = await fetch('{$endpoint}', { method: 'POST', body, credentials: 'same-origin' });
                      const json = await res.json();
                      if (!res.ok || json.success === false) throw new Error(json.message || 'HTTP ' + res.status);
                      const data = Array.isArray(json.data) ? json.data[0] : json.data;
                      // data.message comes from the plugin's language files (links to supertext.com).
                      out.innerHTML = '<span class="' + (data.ok ? 'text-success' : 'text-danger') + '">' + data.message + '</span> '
                        + '<span class="small text-muted">' + esc(data.checked) + '</span>';
                    } catch (e) {
                      out.innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>';
                    }
                    button.disabled = false;
                  });
                });
                JS
            );
        }

        return $note . '<div id="' . $id . '-result" class="mb-2" role="status">' . $current . '</div>'
            . '<button type="button" class="btn btn-secondary" id="' . $id . '">' . Text::_('PLG_SYSTEM_SUPERTEXT_TEST_BUTTON') . '</button>'
            . '<div class="form-text">' . Text::_('PLG_SYSTEM_SUPERTEXT_TEST_DESC') . '</div>';
    }
}
