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
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use Supertext\Plugin\System\Supertext\Api\Languages;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The Languages tab: one row per content language (published or not) with a list of
 * Supertext's target languages and the tone. The list shows the saved choice, else the
 * suggested code (Languages::suggest(): the exact code, else the SDK's default: de-DE for
 * de, es for es-ES), else "— choose —" with a warning: such a language can't be translated
 * until a code is chosen. The tone is offered only where the chosen code has formal/informal.
 * *Translation review* (a checkbox, off by default) sends translations into the language
 * through Supertext's review service (SupertextClient::forReview()).
 * Saving keeps only codes from Supertext's list (filter()). Settings of a language that is no
 * longer a content language are kept as hidden values.
 */
final class LanguagemappingField extends FormField
{
    protected $type = 'Languagemapping';

    private static bool $scriptAdded = false;

    protected function getInput()
    {
        $saved     = Languages::settings($this->value);
        $languages = LanguageHelper::getContentLanguages([0, 1]);
        $e         = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html      = '';

        if (!$languages) {
            $html .= '<p class="text-muted">' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_STATUS_NONE') . '</p>';
        } else {
            $html .= '<table class="table supertext-mapping"><thead><tr>'
                . '<th scope="col">' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_STATUS_LANGUAGE') . '</th>'
                . '<th scope="col">' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_CODE') . '</th>'
                . '<th scope="col">' . Text::_('PLG_SYSTEM_SUPERTEXT_POLITENESS') . '</th>'
                . '<th scope="col">' . Text::_('PLG_SYSTEM_SUPERTEXT_REVIEW') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($languages as $tag => $language) {
                $tag      = (string) $tag;
                $id       = $this->id . '_' . preg_replace('/[^A-Za-z0-9]/', '_', $tag);
                $selected = ($saved[$tag]['code'] ?? '') !== '' ? $saved[$tag]['code'] : (Languages::suggest($tag) ?? '');
                $selected = $selected !== '' ? (Languages::exactTarget($selected) ?? '') : '';
                $tone     = $saved[$tag]['politeness'] ?? 'default';
                $polite   = $selected !== '' && Languages::supportsPoliteness($selected) === true;
                $review   = $saved[$tag]['review'] ?? false;
                $name     = $this->name . '[' . $e($tag) . ']';

                $html .= '<tr><th scope="row"><label for="' . $e($id) . '_code">' . $e((string) $language->title) . '</label> <code>' . $e($tag) . '</code></th><td>'
                    . '<select class="form-select" id="' . $e($id) . '_code" name="' . $name . '[code]" data-supertext-code>'
                    . '<option value="" data-politeness="0"' . ($selected === '' ? ' selected' : '') . '>' . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_CHOOSE') . '</option>';

                foreach (Languages::targets() as $target) {
                    $html .= '<option value="' . $e($target['code']) . '" data-politeness="' . ($target['politeness'] ? '1' : '0') . '"'
                        . ($target['code'] === $selected ? ' selected' : '') . '>'
                        . $e(self::displayName($target['code'], $target['name'])) . ' (' . $e($target['code']) . ')</option>';
                }

                $html .= '</select>'
                    . '<div class="text-danger small mt-1" data-supertext-unmapped' . ($selected === '' ? '' : ' hidden') . '><span class="icon-warning" aria-hidden="true"></span> '
                    . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_NOT_MAPPED') . '</div></td><td>'
                    . '<select class="form-select" id="' . $e($id) . '_politeness" name="' . $name . '[politeness]" aria-label="' . $e(Text::_('PLG_SYSTEM_SUPERTEXT_POLITENESS') . ': ' . $language->title) . '" data-supertext-tone' . ($polite ? '' : ' hidden disabled') . '>';

                foreach (['default', 'more', 'less'] as $option) {
                    $html .= '<option value="' . $option . '"' . ($polite && $tone === $option ? ' selected' : '') . '>' . Text::_('PLG_SYSTEM_SUPERTEXT_POLITENESS_' . strtoupper($option)) . '</option>';
                }

                $html .= '</select><span class="text-muted small" data-supertext-no-tone' . ($polite || $selected === '' ? ' hidden' : '') . '>'
                    . Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_NO_POLITENESS') . '</span></td><td>'
                    . '<input type="hidden" name="' . $name . '[review]" value="0">'
                    . '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="' . $e($id) . '_review" name="' . $name . '[review]" value="1"'
                    . ($review ? ' checked' : '') . ' aria-label="' . $e(Text::_('PLG_SYSTEM_SUPERTEXT_REVIEW') . ': ' . $language->title) . '"></div></td></tr>';
            }

            $html .= '</tbody></table>'
                . '<p class="small text-muted"><strong>' . Text::_('PLG_SYSTEM_SUPERTEXT_REVIEW') . ':</strong> ' . Text::_('PLG_SYSTEM_SUPERTEXT_REVIEW_DESC') . '</p>';
        }

        // Settings of languages that are no longer content languages (e.g. trashed) stay as they are.
        foreach ($saved as $tag => $row) {
            if (!isset($languages[$tag])) {
                $name = $this->name . '[' . $e($tag) . ']';
                $html .= '<input type="hidden" name="' . $name . '[code]" value="' . $e($row['code']) . '">'
                    . '<input type="hidden" name="' . $name . '[politeness]" value="' . $e($row['politeness']) . '">'
                    . '<input type="hidden" name="' . $name . '[review]" value="' . ($row['review'] ? '1' : '0') . '">';
            }
        }

        $this->addScript();

        return $html;
    }

    /** Only Supertext's codes and tones are saved (Languages::clean()). */
    public function filter($value, $group = null, ?Registry $input = null)
    {
        return Languages::clean($value);
    }

    /** "Deutsch (Schweiz)" in the user's back-end language (PHP intl), else Supertext's English name. */
    private static function displayName(string $code, string $english): string
    {
        if (str_starts_with($code, 'gsw-u-sd-')) {
            return Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_' . strtoupper(str_replace('-', '_', $code)));
        }

        if (!class_exists(\Locale::class)) {
            return $english;
        }

        $locale = str_replace('-', '_', Factory::getApplication()->getLanguage()->getTag());
        $name   = \Locale::getDisplayName($code, $locale);

        return \is_string($name) && $name !== $code ? $name : $english;
    }

    /** Shows the tone only for codes that have one, and the warning only for "— choose —". */
    private function addScript(): void
    {
        $app = Factory::getApplication();

        if (self::$scriptAdded || !$app instanceof CMSWebApplicationInterface) {
            return;
        }

        self::$scriptAdded = true;
        $app->getDocument()->getWebAssetManager()->addInlineScript(
            <<<'JS'
            document.addEventListener('change', (event) => {
              const code = event.target;
              if (!(code instanceof HTMLSelectElement) || !code.hasAttribute('data-supertext-code')) return;
              const row = code.closest('tr');
              const polite = code.selectedOptions[0]?.dataset.politeness === '1';
              const tone = row.querySelector('[data-supertext-tone]');
              tone.hidden = !polite;
              tone.disabled = !polite;
              if (!polite) tone.value = 'default';
              row.querySelector('[data-supertext-no-tone]').hidden = polite || code.value === '';
              row.querySelector('[data-supertext-unmapped]').hidden = code.value !== '';
            });
            JS
        );
    }
}
