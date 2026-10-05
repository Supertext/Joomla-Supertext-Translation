<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Extension;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Event\Application\AfterDispatchEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Button\HelpButton;
use Joomla\CMS\Toolbar\ToolbarButton;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Supertext\Plugin\System\Supertext\Api\SupertextClient;
use Supertext\Plugin\System\Supertext\Api\SupertextException;
use Supertext\Plugin\System\Supertext\Console\TranslateCommand;
use Supertext\Plugin\System\Supertext\Service\ArticleTranslator;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Adds a "Supertext" button to the Articles list and the article editor, and the
 * com_ajax endpoint behind it:
 *   administrator/index.php?option=com_ajax&group=system&plugin=supertext&format=json
 */
final class Supertext extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDispatch'                    => 'addButton',
            'onAjaxSupertext'                    => 'handleAjax',
            ApplicationEvents::BEFORE_EXECUTE    => 'registerCommands',
        ];
    }

    /** `php cli/joomla.php supertext:translate` */
    public function registerCommands(ApplicationEvent $event): void
    {
        $app = $event->getApplication();

        if ($app instanceof ConsoleApplication) {
            $this->loadLanguage();
            $app->addCommand(new TranslateCommand(fn (): ArticleTranslator => $this->translator(), $this->getDatabase()));
        }
    }

    public function addButton(AfterDispatchEvent $event): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();

        if (!$app->isClient('administrator') || $input->get('option') !== 'com_content' || $input->get('tmpl') === 'component') {
            return;
        }

        $view   = $input->get('view', 'articles');
        $layout = $input->get('layout', 'default');
        $mode   = match (true) {
            $view === 'articles' || $view === 'featured' => 'list',
            $view === 'article' && $layout === 'edit'    => 'edit',
            default                                      => '',
        };

        $document = $app->getDocument();

        if ($mode === '' || $document->getType() !== 'html' || !$app->getIdentity()?->authorise('core.manage', 'com_content')) {
            return;
        }

        $articleId = $mode === 'edit' ? $input->getInt('id') : 0;

        if ($mode === 'edit' && $articleId === 0) {
            // A new, unsaved article: nothing to translate yet.
            return;
        }

        $label = Text::_('PLG_SYSTEM_SUPERTEXT_BUTTON');
        $html  = $mode === 'list'
            ? '<joomla-toolbar-button list-selection><button type="button" class="btn btn-primary supertext-open" disabled>'
                . '<span class="icon-language" aria-hidden="true"></span> ' . $label . '</button></joomla-toolbar-button>'
            : '<joomla-toolbar-button><button type="button" class="btn btn-primary supertext-open">'
                . '<span class="icon-language" aria-hidden="true"></span> ' . $label . '</button></joomla-toolbar-button>';

        $toolbar = $document->getToolbar('toolbar');
        $toolbar->customButton('supertext')->html($html);

        // Place it before "Help" (and "Options"), next to the editing actions.
        $items = $toolbar->getItems();
        $mine  = array_pop($items);

        foreach ($items as $i => $item) {
            if ($item instanceof HelpButton || ($item instanceof ToolbarButton && \in_array($item->getName(), ['options', 'preferences'], true))) {
                array_splice($items, $i, 0, [$mine]);
                $mine = null;

                break;
            }
        }

        if ($mine !== null) {
            $items[] = $mine;
        }

        $toolbar->setItems($items);

        $document->addScriptOptions('plg_system_supertext', [
            'mode'      => $mode,
            'articleId' => $articleId,
            'endpoint'  => Uri::base() . 'index.php?option=com_ajax&group=system&plugin=supertext&format=json',
            'token'     => Session::getFormToken(),
            'editUrl'   => Uri::base() . 'index.php?option=com_content&task=article.edit&id=',
            'configured' => $this->apiKey() !== '',
        ]);

        foreach ([
            'BUTTON', 'DIALOG_TITLE', 'INTRO_ONE', 'INTRO_MANY', 'SAVE_FIRST', 'TARGETS', 'EXISTS', 'EXISTS_SOME',
            'UNPUBLISHED', 'OVERWRITE', 'OVERWRITE_HINT', 'TRANSLATE', 'TRANSLATING', 'CANCEL', 'CLOSE', 'NO_TARGETS',
            'RESULT_CREATED', 'RESULT_UPDATED', 'RESULT_SKIPPED', 'RESULT_ERROR', 'OPEN', 'NOT_CONFIGURED', 'REVIEW_HINT', 'LOADING',
        ] as $key) {
            Text::script('PLG_SYSTEM_SUPERTEXT_JS_' . $key);
        }

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_system_supertext');
        $wa->useScript('plg_system_supertext.dialog')->useStyle('plg_system_supertext.dialog');
    }

    public function handleAjax(AjaxEvent $event): void
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();

        if (!$app->isClient('administrator') || !$user || $user->guest) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        $input      = $app->getInput();
        $action     = $input->post->getCmd('action');
        $translator = $this->translator();

        $result = match ($action) {
            'describe'  => $translator->describe($input->post->getInt('id'), $user),
            'translate' => $this->translateAll($translator, $input->post->get('ids', [], 'array'), $input->post->get('targets', [], 'array'), $input->post->getBool('overwrite'), $user),
            'test'      => $this->testConnection(),
            default     => throw new \RuntimeException('Unknown action', 400),
        };

        $event->updateEventResult($result);
    }

    /**
     * @param array<mixed> $ids
     * @param array<mixed> $targets
     */
    private function translateAll(ArticleTranslator $translator, array $ids, array $targets, bool $overwrite, $user): array
    {
        $ids     = array_values(array_filter(array_map('intval', $ids)));
        $targets = array_values(array_filter(array_map(static fn ($t) => preg_replace('/[^A-Za-z0-9-]/', '', (string) $t), $targets)));

        if (!$ids || !$targets) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_SUPERTEXT_JS_NO_TARGETS'), 400);
        }

        $results = [];

        foreach ($ids as $id) {
            try {
                array_push($results, ...$translator->translate($id, $targets, $overwrite, $user));
            } catch (\Throwable $e) {
                $results[] = ['article' => $id, 'language' => '', 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return ['results' => $results];
    }

    private function testConnection(): array
    {
        $client = $this->clientOrNull();

        if (!$client) {
            throw new SupertextException(Text::_('PLG_SYSTEM_SUPERTEXT_JS_NOT_CONFIGURED'));
        }

        $client->validateApiKey();

        return ['ok' => true];
    }

    private function translator(): ArticleTranslator
    {
        $languages = [];

        foreach ((array) $this->params->get('languages', []) as $row) {
            $row = (array) $row;
            $tag = (string) ($row['language'] ?? '');

            if ($tag !== '') {
                $languages[$tag] = ['code' => (string) ($row['code'] ?? ''), 'politeness' => (string) ($row['politeness'] ?? 'default')];
            }
        }

        return new ArticleTranslator(
            $this->getApplication(),
            $this->getDatabase(),
            $this->clientOrNull() ?? $this->client(''),
            $languages,
            (int) $this->params->get('target_state', 0),
            (bool) $this->params->get('custom_fields', 1),
        );
    }

    private function apiKey(): string
    {
        $env = getenv('SUPERTEXT_API_KEY');

        return SupertextClient::normalizeKey(\is_string($env) && $env !== '' ? $env : (string) $this->params->get('api_key', ''));
    }

    private function clientOrNull(): ?SupertextClient
    {
        $key = $this->apiKey();

        return $key === '' ? null : $this->client($key);
    }

    private function client(string $key): SupertextClient
    {
        $endpoint = getenv('SUPERTEXT_API_ENDPOINT');
        $baseUrl  = SupertextClient::baseUrlFor(
            (string) $this->params->get('environment', 'live'),
            \is_string($endpoint) && $endpoint !== '' ? $endpoint : (string) $this->params->get('endpoint', '')
        );

        $http      = (new HttpFactory())->getHttp([], ['curl', 'stream']);
        $transport = static function (string $method, string $url, array $headers, ?string $body) use ($http): array {
            $response = match ($method) {
                'GET'    => $http->get($url, $headers, 30),
                'DELETE' => $http->delete($url, $headers, 30),
                default  => $http->post($url, (string) $body, $headers, 30),
            };

            $flat = [];

            foreach ($response->getHeaders() as $name => $values) {
                $flat[$name] = implode(', ', (array) $values);
            }

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'headers' => $flat];
        };

        return new SupertextClient(
            $key,
            $baseUrl,
            $transport,
            max(10, (int) $this->params->get('poll_timeout', 180)),
            max(0.5, (float) $this->params->get('poll_interval', 2)),
        );
    }
}
