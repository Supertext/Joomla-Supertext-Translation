<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Extension;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Application\AfterDispatchEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\PageCache\IsExcludedEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Button\HelpButton;
use Joomla\CMS\Toolbar\ToolbarButton;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Supertext\Plugin\System\Supertext\Api\CallableTransport;
use Supertext\Plugin\System\Supertext\Api\FileTransfer;
use Supertext\Plugin\System\Supertext\Api\FileTransferException;
use Supertext\Plugin\System\Supertext\Api\Languages;
use Supertext\Plugin\System\Supertext\Api\Reporting;
use Supertext\Plugin\System\Supertext\Api\SupertextClient;
use Supertext\Plugin\System\Supertext\Console\TranslateCommand;
use Supertext\Plugin\System\Supertext\PluginHealth\KeyCheck;
use Supertext\Plugin\System\Supertext\Service\ArticleTranslator;
use Supertext\Plugin\System\Supertext\Service\ExtensionStateStorage;
use Supertext\Plugin\System\Supertext\Service\Messages;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Adds a "Supertext" button to the Articles list and the article editor, and the
 * com_ajax endpoint behind it:
 *   administrator/index.php?option=com_ajax&group=system&plugin=supertext&format=json
 *
 * On the site, the same com_ajax address (index.php?option=com_ajax&plugin=supertext&group=system&format=json)
 * is the public ping endpoint of the Commando Center (see Api\Reporting).
 */
final class Supertext extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /** @var array{0: string, 1: int}|null the reporter action to run after the response (see reportLater()) */
    private ?array $pending = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise'                  => 'pingBeforeRouting',
            'onAfterDispatch'                    => 'addButton',
            'onAjaxSupertext'                    => 'handleAjax',
            'onExtensionBeforeSave'              => 'keepApiKey',
            'onExtensionAfterSave'               => 'afterSettingsSaved',
            'onPageCacheIsExcluded'              => 'excludeFromPageCache',
            ApplicationEvents::BEFORE_EXECUTE    => 'registerCommands',
        ];
    }

    /** `php cli/joomla.php supertext:translate` */
    public function registerCommands(ApplicationEvent $event): void
    {
        $app = $event->getApplication();

        if ($app instanceof ConsoleApplication) {
            $this->loadLanguage();
            $app->addCommand(new TranslateCommand(
                fn (): ArticleTranslator => $this->translator(),
                $this->getDatabase(),
                fn (array $results) => $this->reportAfterTranslation($results),
            ));
        }
    }

    /**
     * The API key field is locked (disabled) until the administrator clicks "Modify", and a
     * disabled field isn't submitted: keep the stored key when the form didn't send one, so
     * saving other settings never deletes it. (Modify + an empty field still removes it.)
     */
    public function keepApiKey(BeforeSaveEvent $event): void
    {
        $table  = $event->getItem();
        $params = $event->getData()['params'] ?? null;

        if ($event->getContext() !== 'com_plugins.plugin' || ($table->type ?? '') !== 'plugin'
            || ($table->folder ?? '') !== 'system' || ($table->element ?? '') !== 'supertext'
            || !\is_array($params) || \array_key_exists('api_key', $params)) {
            return;
        }

        $stored = (string) $this->params->get('api_key', '');

        if ($stored !== '') {
            $registry = new Registry($table->params ?? '{}');
            $registry->set('api_key', $stored);
            $table->params = (string) $registry;
        }
    }

    /**
     * After the plugin's settings were saved: the automatic API key check (shown as a message on
     * the settings page) and the `installed`/`settings_saved` version report. Never fails the save.
     */
    public function afterSettingsSaved(AfterSaveEvent $event): void
    {
        try {
            $table = $event->getItem();

            if ($event->getContext() !== 'com_plugins.plugin' || ($table->type ?? '') !== 'plugin'
                || ($table->folder ?? '') !== 'system' || ($table->element ?? '') !== 'supertext') {
                return;
            }

            // The settings just saved, not the ones this request started with.
            $this->params = new Registry($table->params ?? '{}');
            $result       = $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, $this->apiKey());

            if ($result?->check) {
                $this->getApplication()->enqueueMessage(
                    Messages::keyCheck($result->check, $this->getApplication()->getLanguage()),
                    $result->check->status === KeyCheck::OK ? 'message' : 'warning'
                );
            }
        } catch (\Throwable) {
            // Reporting never breaks a save.
        }
    }

    /**
     * The ping is answered before Joomla routes the request: on multilingual sites the Language
     * Filter would otherwise redirect index.php?option=com_ajax… to /en/…, and the Commando
     * Center follows no redirects. (Sites without it reach handleAjax() the usual way.)
     */
    public function pingBeforeRouting(): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();

        if ($app->isClient('site') && $input->get->getCmd('option') === 'com_ajax' && $input->get->getCmd('plugin') === 'supertext') {
            $this->answerPing();
        }
    }

    /** The ping endpoint must never come from Joomla's page cache. */
    public function excludeFromPageCache(IsExcludedEvent $event): void
    {
        $input = $this->getApplication()->getInput();

        if ($input->getCmd('option') === 'com_ajax' && $input->getCmd('plugin') === 'supertext') {
            $event->addResult(true);
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

        if (!$app instanceof CMSWebApplicationInterface) {
            return;
        }

        $document = $app->getDocument();

        if ($mode === '' || !$document instanceof HtmlDocument || !$app->getIdentity()?->authorise('core.manage', 'com_content')) {
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
            'EXPORT', 'IMPORT', 'FORMAT', 'FORMAT_XLIFF', 'FORMAT_HTML', 'DOWNLOAD', 'EXPORT_ONE', 'EXPORT_HINT', 'FILE', 'INCLUDE_CHANGED',
            'IMPORTING', 'IMPORT_HINT', 'NO_FILE', 'SUMMARY_OK', 'SUMMARY_CHANGED', 'SUMMARY_UNTRANSLATED', 'SUMMARY_INVALID', 'SUMMARY_MISSING',
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

        if ($app->isClient('site')) {
            $this->answerPing();

            return;
        }

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
            'export'    => $this->sendExport($translator, $input->post->getInt('id'), $input->post->get('targets', [], 'array'), $input->post->getCmd('format'), $user),
            'import'    => $this->importUpload($translator, $input->files->get('file', [], 'raw'), $input->post->getBool('include_changed'), $input->post->getBool('overwrite'), $user),
            default     => throw new \RuntimeException('Unknown action', 400),
        };

        if ($action === 'describe') {
            $this->reportLater(Reporting::ON_REQUEST);
        }

        $event->updateEventResult($result);
    }

    /**
     * GET index.php?option=com_ajax&plugin=supertext&group=system&format=json&challenge=… on the site:
     * the Commando Center's reachability check. No login, no token; status, headers and body exactly
     * as the SDK builds them (404 while version reports are off). Bypasses com_ajax's JSON wrapper.
     */
    private function answerPing(): void
    {
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $method = strtoupper((string) $input->getMethod());
        $answer = \in_array($method, ['GET', 'HEAD'], true)
            ? Reporting::ping($input->get->get('challenge', null, 'raw'), $this->reportsEnabled())
            : null;

        if (!headers_sent()) {
            http_response_code($answer->status ?? 405);
            header('Content-Type: application/json', true);
            header('Cache-Control: no-store', true);

            foreach ($answer->headers ?? [] as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($method !== 'HEAD') {
            echo $answer->body ?? '{"ok":false}';
        }

        $app->close();
    }

    /**
     * "Export for translation": sends the file as a download and ends the request (bypasses
     * com_ajax's JSON wrapper); errors go back as com_ajax JSON like the other actions.
     *
     * @param array<mixed> $targets
     *
     * @return array{} nothing: the request ends here
     */
    private function sendExport(ArticleTranslator $translator, int $id, array $targets, string $format, $user): array
    {
        $targets = array_values(array_filter(array_map(static fn ($t) => preg_replace('/[^A-Za-z0-9-]/', '', (string) $t), $targets)));

        try {
            $file = $translator->exportFile($id, $targets, $format === FileTransfer::HTML ? FileTransfer::HTML : FileTransfer::XLIFF, $user);
        } catch (FileTransferException $e) {
            throw new \RuntimeException(Messages::of($e, $this->getApplication()->getLanguage()), 400);
        }

        $app = $this->getApplication();

        if (!headers_sent()) {
            header('Content-Type: ' . $file['type'], true);
            header('Content-Disposition: attachment; filename="' . $file['name'] . '"', true);
            header('Content-Length: ' . \strlen($file['body']), true);
            header('Cache-Control: no-store', true);
            header('X-Content-Type-Options: nosniff', true);
        }

        echo $file['body'];
        $app->close();

        return [];
    }

    /**
     * "Import translation": the uploaded file (never stored, read in memory) written back, with
     * a summary per job.
     *
     * @param mixed $upload the file from $_FILES
     *
     * @return array{results: list<array<string, mixed>>}
     */
    private function importUpload(ArticleTranslator $translator, mixed $upload, bool $includeChanged, bool $overwrite, $user): array
    {
        $language = $this->getApplication()->getLanguage();

        try {
            $error = \is_array($upload) ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

            if (\in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new FileTransferException('too_large');
            }

            $path = \is_array($upload) ? (string) ($upload['tmp_name'] ?? '') : '';

            if ($error !== UPLOAD_ERR_OK || $path === '' || !is_uploaded_file($path)) {
                throw new FileTransferException('no_file');
            }

            // Refuse large files before reading them.
            FileTransfer::assertUploadSize((int) max((int) ($upload['size'] ?? 0), (int) filesize($path)));
            $results = $translator->importFile((string) file_get_contents($path), $includeChanged, $overwrite, $user);
        } catch (FileTransferException $e) {
            throw new \RuntimeException(Messages::of($e, $language), 400);
        }

        return ['results' => array_map(static function (array $r) use ($language): array {
            if (isset($r['error'])) {
                $r['message'] = Messages::of($r['error'], $language);
                unset($r['error']);
            }

            return $r;
        }, $results)];
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
                $results[] = ['article' => $id, 'language' => '', 'status' => 'error', 'message' => Messages::of($e, $this->getApplication()->getLanguage())];
            }
        }

        $this->reportAfterTranslation($results);

        // reason/httpStatus are for the key check only.
        return ['results' => array_map(static fn (array $r): array => array_diff_key($r, ['reason' => 0, 'httpStatus' => 0]), $results)];
    }

    /**
     * "Test connection": the automatic API key check (cost-free `GET features`), stored and shown
     * with the Commando Center's texts; a changed result is reported.
     *
     * @return array{ok: bool, status: string, message: string, checked: string}
     */
    private function testConnection(): array
    {
        $result = $this->reporting()->run(Reporting::TEST_CONNECTION, $this->apiKey());
        $check  = $result->check ?? KeyCheck::from($this->client($this->apiKey())->checkApiKey()) ?? new KeyCheck(KeyCheck::ERROR, KeyCheck::timestamp(microtime(true)));

        return [
            'ok'      => $check->status === KeyCheck::OK,
            'status'  => $check->status,
            'message' => Messages::keyCheck($check, $this->getApplication()->getLanguage()),
            'checked' => $this->checkedAt($check),
        ];
    }

    /** "checked 13.10.2026 09:15" in the user's language and time zone. */
    public function checkedAt(KeyCheck $check): string
    {
        try {
            $time = HTMLHelper::_('date', $check->checkedAt, Text::_('DATE_FORMAT_LC5'));
        } catch (\Throwable) {
            $time = $check->checkedAt;
        }

        return Text::sprintf('PLG_SYSTEM_SUPERTEXT_KEYCHECK_CHECKED_AT', $time);
    }

    /** The latest stored API key check, for the settings page. */
    public function lastCheck(): ?KeyCheck
    {
        return $this->reporting()->lastCheck();
    }

    /** "Send version information to Supertext", with SUPERTEXT_VERSION_REPORTS winning. */
    public function reportsEnabled(): bool
    {
        return Reporting::enabled($this->params->get('version_reports', 1));
    }

    /** The ping endpoint's address (shown on the settings page), or null if the site URL isn't known. */
    public function pingUrl(): ?string
    {
        $site = $this->siteUrl();

        return $site !== null ? Reporting::callbackUrl($site) : null;
    }

    /**
     * Runs a reporter action (version report, key check if the key changed) after the response,
     * so the back end never waits for it: on PHP-FPM the response is finished first; otherwise it
     * runs at the end of the request. Only one action per request (a rejected key wins).
     */
    public function reportLater(string $action, int $httpStatus = 401): void
    {
        $first = $this->pending === null;

        if ($first || $action === Reporting::AUTHENTICATION_FAILED) {
            $this->pending = [$action, $httpStatus];
        }

        if ($first) {
            register_shutdown_function(function (): void {
                if (\function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }

                [$action, $status] = $this->pending ?? [Reporting::ON_REQUEST, 401];
                $this->reporting()->run($action, $this->apiKey(), $status);
            });
        }
    }

    /**
     * After translating: a 401/403 from Supertext marks the key invalid (reported once when the
     * status changes); otherwise the usual back-end check (upgrades, a changed key).
     *
     * @param list<array<string, mixed>> $results ArticleTranslator::translate() results
     */
    public function reportAfterTranslation(array $results): void
    {
        foreach ($results as $result) {
            if (($result['reason'] ?? '') === 'auth_failed') {
                $status = (int) ($result['httpStatus'] ?? 401);
                $this->reportLater(Reporting::AUTHENTICATION_FAILED, \in_array($status, [401, 403], true) ? $status : 401);

                return;
            }
        }

        $this->reportLater(Reporting::ON_REQUEST);
    }

    /** The Commando Center glue with this site's settings, versions and storage. */
    private function reporting(): Reporting
    {
        return new Reporting(
            $this->reportsEnabled(),
            $this->siteUrl(),
            Reporting::pluginVersion(\dirname(__DIR__, 2) . '/supertext.xml'),
            (new Version())->getShortVersion(),
            new CallableTransport($this->transport()),
            $this->baseUrl(),
            new ExtensionStateStorage($this->getDatabase()),
        );
    }

    /** The site's base URL as Joomla knows it (live_site wins); on the CLI live_site, else null (the stored one is used). */
    private function siteUrl(): ?string
    {
        $app = $this->getApplication();

        if ($app instanceof CMSWebApplicationInterface) {
            return Reporting::siteUrl(Uri::root());
        }

        return Reporting::siteUrl($app->get('live_site'));
    }

    /**
     * The Languages tab (both the current format and the rows of 0.1.0, see Languages::settings()).
     *
     * @return array<string, array{code: string, politeness: string, review: bool}> keyed by content language tag
     */
    private function languageSettings(): array
    {
        return Languages::settings($this->params->get('languages', []));
    }

    private function translator(): ArticleTranslator
    {
        return new ArticleTranslator(
            $this->getApplication(),
            $this->getDatabase(),
            $this->clientOrNull() ?? $this->client(''),
            $this->languageSettings(),
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

    private function baseUrl(): string
    {
        $endpoint = getenv('SUPERTEXT_API_ENDPOINT');

        return SupertextClient::baseUrlFor(
            (string) $this->params->get('environment', 'live'),
            \is_string($endpoint) && $endpoint !== '' ? $endpoint : (string) $this->params->get('endpoint', '')
        );
    }

    /**
     * Joomla's HTTP client (its proxy settings apply) as the SDK's transport, for translations,
     * the key check and the version reports.
     *
     * @return \Closure(string, string, array<string, string>, ?string, float=): array{status: int, body: string, headers: array<string, string>}
     */
    private function transport(): \Closure
    {
        $http = (new HttpFactory())->getHttp([], ['curl', 'stream']);

        return static function (string $method, string $url, array $headers, ?string $body, float $timeout = 30.0) use ($http): array {
            $seconds  = max(1, (int) ceil($timeout));
            $response = match ($method) {
                'GET'    => $http->get($url, $headers, $seconds),
                'DELETE' => $http->delete($url, $headers, $seconds),
                default  => $http->post($url, (string) $body, $headers, $seconds),
            };

            $flat = [];

            foreach ($response->getHeaders() as $name => $values) {
                $flat[$name] = implode(', ', (array) $values);
            }

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'headers' => $flat];
        };
    }

    /**
     * The review service for languages with Translation review on: SUPERTEXT_REVIEW_URL, else the
     * setting (never saved: Supertext's review service; saved empty: review off everywhere).
     */
    private function reviewUrl(): string
    {
        $env = getenv('SUPERTEXT_REVIEW_URL');

        if (\is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return $this->params->exists('review_url') ? trim((string) $this->params->get('review_url', '')) : SupertextClient::REVIEW_URL;
    }

    private function client(string $key): SupertextClient
    {
        return new SupertextClient(
            $key,
            $this->baseUrl(),
            $this->transport(),
            max(10, (int) $this->params->get('poll_timeout', 180)),
            max(0.5, (float) $this->params->get('poll_interval', 2)),
            reviewUrl: $this->reviewUrl(),
            version: Reporting::pluginVersion(\dirname(__DIR__, 2) . '/supertext.xml'),
        );
    }
}
