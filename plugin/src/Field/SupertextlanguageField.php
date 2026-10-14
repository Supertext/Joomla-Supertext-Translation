<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Field;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Supertext\Plugin\System\Supertext\Api\Languages;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The "Supertext language" of a row on the Languages tab: one of Supertext's target codes
 * (the SDK's list), named in the user's back-end language. Empty means "the content
 * language's own tag, if Supertext has it" (de-CH, fr-FR); every other content language
 * needs a code here (es-ES → es). Choosing a content language in a row pre-selects its exact
 * Supertext code, if there is one. A saved code that isn't in the list stays selectable, so
 * nothing the administrator set disappears.
 */
final class SupertextlanguageField extends ListField
{
    protected $type = 'Supertextlanguage';

    private static bool $scriptAdded = false;

    protected function getOptions()
    {
        $options = [HTMLHelper::_('select.option', '', Text::_('PLG_SYSTEM_SUPERTEXT_LANGUAGE_CODE_AUTO'))];
        $current = trim((string) $this->value);
        $known   = false;

        foreach (Languages::targets() as $language) {
            $options[] = HTMLHelper::_('select.option', $language['code'], self::name($language['code'], $language['name']) . ' — ' . $language['code']);
            $known     = $known || strcasecmp($language['code'], $current) === 0;
        }

        if ($current !== '' && !$known) {
            $options[] = HTMLHelper::_('select.option', $current, Text::sprintf('PLG_SYSTEM_SUPERTEXT_LANGUAGE_CODE_UNKNOWN', $current));
        }

        $this->addPrefillScript();

        return array_merge(parent::getOptions(), $options);
    }

    /** "Deutsch (Schweiz)" in the user's back-end language (PHP intl), else Supertext's English name. */
    private static function name(string $code, string $english): string
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

    private function addPrefillScript(): void
    {
        $app = Factory::getApplication();

        if (self::$scriptAdded || !$app instanceof CMSWebApplicationInterface) {
            return;
        }

        self::$scriptAdded = true;
        $app->getDocument()->getWebAssetManager()->addInlineScript(
            <<<'JS'
            document.addEventListener('change', (event) => {
              const select = event.target;
              const row = select instanceof HTMLSelectElement && select.name.endsWith('[language]') ? select.closest('.subform-repeatable-group') : null;
              const code = row ? row.querySelector('select[name$="[code]"]') : null;
              if (!code || code.value !== '') return;
              const key = (s) => s.toLowerCase().replace(/_/g, '-');
              const match = Array.from(code.options).find((o) => o.value !== '' && key(o.value) === key(select.value));
              if (match) code.value = match.value;
            });
            JS
        );
    }
}
