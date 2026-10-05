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
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * "Test connection" button in the plugin settings. Checks the saved key with the
 * cost-free `GET /v1/features` call.
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
        $ok       = json_encode(Text::_('PLG_SYSTEM_SUPERTEXT_TEST_OK'));
        $id       = 'supertext-test-' . uniqid();

        Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(
            <<<JS
            document.addEventListener('DOMContentLoaded', () => {
              const button = document.getElementById('{$id}');
              const out = document.getElementById('{$id}-result');
              button.addEventListener('click', async () => {
                button.disabled = true;
                out.className = 'ms-2';
                out.textContent = '…';
                const body = new FormData();
                body.append('{$token}', '1');
                body.append('action', 'test');
                try {
                  const res = await fetch('{$endpoint}', { method: 'POST', body, credentials: 'same-origin' });
                  const json = await res.json();
                  if (!res.ok || json.success === false) throw new Error(json.message || 'HTTP ' + res.status);
                  out.className = 'ms-2 text-success';
                  out.textContent = {$ok};
                } catch (e) {
                  out.className = 'ms-2 text-danger';
                  out.textContent = e.message;
                }
                button.disabled = false;
              });
            });
            JS
        );

        return $note . '<button type="button" class="btn btn-secondary" id="' . $id . '">'
            . Text::_('PLG_SYSTEM_SUPERTEXT_TEST_BUTTON') . '</button><span id="' . $id . '-result" class="ms-2" role="status"></span>'
            . '<div class="form-text">' . Text::_('PLG_SYSTEM_SUPERTEXT_TEST_DESC') . '</div>';
    }
}
