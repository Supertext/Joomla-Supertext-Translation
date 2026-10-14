<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

use Supertext\Plugin\System\Supertext\Sdk\Http\HttpTransport;
use Supertext\Plugin\System\Supertext\Sdk\V1\ApiKeyCheck;
use Supertext\Plugin\System\Supertext\Sdk\V1\InstallationReporter;
use Supertext\Plugin\System\Supertext\Sdk\V1\PingResponse;
use Supertext\Plugin\System\Supertext\Sdk\V1\ReporterResult;
use Supertext\Plugin\System\Supertext\Sdk\V1\ReporterState;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the
 * callback ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report protocol).
 *
 * The protocol is the SDK's (InstallationReporter, pingResponse); this class only gives it
 * what the plugin knows (site URL, plugin and Joomla versions, the HTTP transport) and keeps
 * the reporter's state in a StateStorage. Nothing here ever throws: a failing report, key
 * check or database never breaks a save or a translation.
 *
 * No Joomla dependency.
 */
final class Reporting
{
    /** The plugin's GitHub repository name: the center reads its releases from there. */
    public const PLUGIN_NAME = 'Joomla-Supertext-Translation';

    public const SYSTEM_NAME = 'Joomla';

    /** The public ping endpoint, below the site's base URL (com_ajax on the site). */
    public const PING_PATH = 'index.php?option=com_ajax&plugin=supertext&group=system&format=json';

    public const AFTER_SETTINGS_SAVED = 'afterSettingsSaved';
    public const ON_REQUEST = 'onRequest';
    public const AUTHENTICATION_FAILED = 'authenticationFailed';
    public const TEST_CONNECTION = 'testConnection';
    public const REPORT_CHECK = 'reportCheck';

    /** @var (callable(): float)|null */
    private $now;

    /** @var (callable(float): void)|null */
    private $sleep;

    /**
     * @param bool        $enabled       "Send version information to Supertext" combined with SUPERTEXT_VERSION_REPORTS (enabled())
     * @param string|null $siteUrl       the site's base URL as Joomla knows it; null (CLI without live_site): the one stored last
     * @param string|null $reportUrl     for tests; default SUPERTEXT_REPORT_URL, else the SDK's center
     * @param (callable(): float)|null       $now
     * @param (callable(float): void)|null   $sleep
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $siteUrl,
        private readonly string $pluginVersion,
        private readonly ?string $systemVersion,
        private readonly HttpTransport $transport,
        private readonly string $apiBaseUrl,
        private readonly StateStorage $storage,
        private readonly ?string $reportUrl = null,
        ?callable $now = null,
        ?callable $sleep = null,
    ) {
        $this->now   = $now;
        $this->sleep = $sleep;
    }

    /** Whether version reports are on: SUPERTEXT_VERSION_REPORTS (0/false/off/no, 1/true/on/yes) wins over the setting (default on). */
    public static function enabled(mixed $setting): bool
    {
        return InstallationReporter::reportsEnabled($setting === null || $setting === '' ? null : (bool) (int) $setting);
    }

    /** The value of SUPERTEXT_VERSION_REPORTS when it overrides the setting, else null. */
    public static function environmentOverride(): ?string
    {
        $value = getenv('SUPERTEXT_VERSION_REPORTS');

        if (!\is_string($value) || !\in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no', '1', 'true', 'on', 'yes'], true)) {
            return null;
        }

        return trim($value);
    }

    /** An absolute http(s) base URL without trailing slash, else null. */
    public static function siteUrl(mixed $url): ?string
    {
        if (!\is_string($url) || !preg_match('#^https?://[^/?\#\s]+#i', trim($url))) {
            return null;
        }

        return rtrim(trim($url), '/');
    }

    /** The ping endpoint's absolute URL for a site URL. */
    public static function callbackUrl(string $siteUrl): string
    {
        return rtrim($siteUrl, '/') . '/' . self::PING_PATH;
    }

    /** The answer of the ping endpoint (status, headers and body exactly as the SDK builds them). */
    public static function ping(mixed $challenge, bool $enabled): PingResponse
    {
        return InstallationReporter::pingResponse(\is_string($challenge) ? $challenge : null, self::PLUGIN_NAME, $enabled);
    }

    /** The plugin version from its manifest (the official version source, also shown in the extension manager). */
    public static function pluginVersion(string $manifest): string
    {
        $xml = is_file($manifest) ? @simplexml_load_file($manifest) : false;
        $version = $xml !== false ? trim((string) $xml->version) : '';

        return $version !== '' ? $version : '0.0.0';
    }

    /**
     * Runs one reporter action with the stored state and stores the new state. Never throws.
     *
     * @param string $action one of the constants above
     * @param int    $httpStatus authenticationFailed: Supertext's 401 or 403
     */
    public function run(string $action, #[\SensitiveParameter] string $apiKey, int $httpStatus = 401): ?ReporterResult
    {
        try {
            try {
                $data   = $this->storage->load();
                $stored = true;
            } catch (\Throwable) {
                // Without the stored state every request would look like a new installation: report nothing.
                $data   = [];
                $stored = false;
            }

            $site     = $this->siteUrl ?? self::siteUrl($data['siteUrl'] ?? null);
            $reporter = new InstallationReporter(
                enabled: $this->enabled && $stored && $site !== null,
                siteUrl: $site ?? '',
                pluginName: self::PLUGIN_NAME,
                pluginVersion: $this->pluginVersion,
                systemName: self::SYSTEM_NAME,
                systemVersion: $this->systemVersion,
                callbackUrl: $site !== null ? self::callbackUrl($site) : null,
                reportUrl: $this->reportUrl,
                transport: $this->transport,
                apiBaseUrl: $this->apiBaseUrl,
                sleep: $this->sleep,
                now: $this->now,
            );
            $state = ReporterState::from($data['reporter'] ?? null);

            $result = match ($action) {
                self::AFTER_SETTINGS_SAVED  => $reporter->afterSettingsSaved($state, $apiKey),
                self::AUTHENTICATION_FAILED => $reporter->authenticationFailed($state, $apiKey, $httpStatus),
                self::TEST_CONNECTION       => $reporter->testConnection($state, $apiKey),
                self::REPORT_CHECK          => $reporter->reportCheck($state),
                default                     => $reporter->onRequest($state, $apiKey),
            };

            if ($stored) {
                try {
                    $this->storage->save(['reporter' => $result->state->toArray()] + ($site !== null ? ['siteUrl' => $site] : []));
                } catch (\Throwable) {
                    // The next request tries again.
                }
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The latest stored key check (for the settings page), or null. */
    public function lastCheck(): ?ApiKeyCheck
    {
        try {
            return ReporterState::from($this->storage->load()['reporter'] ?? null)->keyCheck;
        } catch (\Throwable) {
            return null;
        }
    }
}
