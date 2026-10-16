<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\TestCase;
use Supertext\Plugin\System\Supertext\Api\CallableTransport;
use Supertext\Plugin\System\Supertext\Api\Reporting;
use Supertext\Plugin\System\Supertext\Api\StateStorage;
use Supertext\Plugin\System\Supertext\Sdk\Supertext;

/** The Commando Center glue: when Supertext Plugin Health's reporter (with the SDK's key check) runs, what it sends, where its state goes, the ping. */
final class ReportingTest extends TestCase
{
    private const KEY    = 'secret-test-key';
    private const CENTER = 'https://center.test/api/v1/report';

    /** @var list<array{method: string, url: string, body: ?string, timeout: float}> */
    private array $calls = [];

    /** HTTP status Supertext answers `GET features` with. */
    private int $featuresStatus = 200;

    private int $centerStatus = 202;

    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        putenv('SUPERTEXT_VERSION_REPORTS');
    }

    protected function tearDown(): void
    {
        putenv('SUPERTEXT_VERSION_REPORTS');
    }

    private function reporting(bool $enabled = true, ?string $site = 'https://www.example.ch/', string $version = '0.2.0'): Reporting
    {
        $transport = new CallableTransport(function (string $method, string $url, array $headers, ?string $body, float $timeout): array {
            $this->calls[] = compact('method', 'url', 'body', 'timeout');

            return str_starts_with($url, self::CENTER)
                ? ['status' => $this->centerStatus, 'body' => '{"ok":true}']
                : ['status' => $this->featuresStatus, 'body' => $this->featuresStatus === 200 ? '{"supported_features":[]}' : '{"error_code":"UNAUTHORIZED"}'];
        });

        return new Reporting(
            $enabled,
            Reporting::siteUrl($site),
            $version,
            '6.1.4',
            $transport,
            'https://api.test/v1/',
            $this->storage,
            self::CENTER,
            static fn (): float => 1_792_000_000.0,
            static function (): void {
            },
        );
    }

    /** @return list<array<string, mixed>> the reports sent to the center */
    private function reports(): array
    {
        return array_values(array_map(
            static fn (array $c): array => json_decode((string) $c['body'], true),
            array_filter($this->calls, static fn (array $c): bool => $c['url'] === self::CENTER)
        ));
    }

    private function keyChecks(): int
    {
        return \count(array_filter($this->calls, static fn (array $c): bool => $c['url'] === 'https://api.test/v1/features'));
    }

    public function testTheSettingAndTheEnvironment(): void
    {
        self::assertTrue(Reporting::enabled(null), 'default on');
        self::assertTrue(Reporting::enabled('1'));
        self::assertFalse(Reporting::enabled('0'));
        self::assertFalse(Reporting::enabled(0));
        self::assertNull(Reporting::environmentOverride());

        putenv('SUPERTEXT_VERSION_REPORTS=0');
        self::assertFalse(Reporting::enabled('1'));
        self::assertSame('0', Reporting::environmentOverride());

        putenv('SUPERTEXT_VERSION_REPORTS=on');
        self::assertTrue(Reporting::enabled('0'));
    }

    public function testSiteAndCallbackUrls(): void
    {
        self::assertSame('https://www.example.ch', Reporting::siteUrl('https://www.example.ch/'));
        self::assertSame('http://example.ch/joomla', Reporting::siteUrl(' http://example.ch/joomla/ '));
        self::assertNull(Reporting::siteUrl('/'));
        self::assertNull(Reporting::siteUrl(null));
        self::assertSame(
            'https://www.example.ch/index.php?option=com_ajax&plugin=supertext&group=system&format=json',
            Reporting::callbackUrl('https://www.example.ch')
        );
    }

    public function testThePingEndpoint(): void
    {
        $ok = Reporting::ping('abcdefghijklmnop_1234', true);
        self::assertSame(200, $ok->status);
        self::assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $ok->headers);
        self::assertSame('{"ok":true,"plugin":"Joomla-Supertext-Translation","challenge":"abcdefghijklmnop_1234"}', $ok->body);

        self::assertSame(400, Reporting::ping('<script>', true)->status);
        self::assertSame(400, Reporting::ping(['x'], true)->status);
        self::assertSame(400, Reporting::ping(null, true)->status);
        self::assertSame(404, Reporting::ping('abcdefghijklmnop_1234', false)->status);
    }

    public function testTheFirstBackEndRequestChecksTheKeyAndReportsTheInstallation(): void
    {
        $result = $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);

        self::assertSame('installed', $result?->event);
        self::assertTrue($result->sent);
        self::assertSame('ok', $result->check?->status);
        self::assertSame(1, $this->keyChecks());

        [$report] = $this->reports();
        self::assertSame('https://www.example.ch', $report['site_url']);
        self::assertSame(['name' => 'Joomla-Supertext-Translation', 'version' => '0.2.0'], $report['plugin']);
        self::assertSame(['name' => 'Joomla', 'version' => '6.1.4'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version'], 'the bundled SDK');
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);
        self::assertSame(['url' => 'https://www.example.ch/index.php?option=com_ajax&plugin=supertext&group=system&format=json'], $report['callback']);

        $report = end($this->calls);
        self::assertSame(3.0, $report['timeout']);

        foreach ($this->calls as $call) {
            self::assertStringNotContainsString(self::KEY, (string) $call['body']);
        }

        self::assertSame('https://www.example.ch', $this->storage->data['siteUrl']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($this->storage->data));
        self::assertNotEmpty($this->storage->data['reporter']['keyCheck']['fingerprint']);
    }

    public function testLaterRequestsSendNothingUntilSomethingChanges(): void
    {
        $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        $this->calls = [];

        self::assertNull($this->reporting()->run(Reporting::ON_REQUEST, self::KEY)?->event);
        self::assertSame([], $this->calls);

        // Plugin update: reported, without a new key check.
        self::assertSame('upgraded', $this->reporting(version: '0.3.0')->run(Reporting::ON_REQUEST, self::KEY)?->event);
        self::assertSame(0, $this->keyChecks());

        // A new key: checked again.
        $this->calls          = [];
        $this->featuresStatus = 401;
        $result               = $this->reporting(version: '0.3.0')->run(Reporting::ON_REQUEST, 'another-key');
        self::assertSame(1, $this->keyChecks());
        self::assertSame('invalid', $result?->check?->status);
        self::assertSame('api_key_check', $result->event);
    }

    public function testARejectedTranslationMarksTheKeyInvalidOnce(): void
    {
        $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        $this->calls = [];

        $result = $this->reporting()->run(Reporting::AUTHENTICATION_FAILED, self::KEY, 403);
        self::assertSame('invalid', $result?->check?->status);
        self::assertSame(403, $result->check->httpStatus);
        self::assertSame(0, $this->keyChecks(), 'no request to Supertext');
        self::assertSame(['api_key_check'], array_column($this->reports(), 'event'));

        $this->calls = [];
        $this->reporting()->run(Reporting::AUTHENTICATION_FAILED, self::KEY, 401);
        self::assertSame([], $this->reports(), 'reported only when the status changes');
    }

    public function testSettingsSavedAndTestConnection(): void
    {
        self::assertSame('installed', $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, self::KEY)?->event);
        self::assertSame('settings_saved', $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, self::KEY)?->event);
        self::assertSame(2, $this->keyChecks());

        $this->calls = [];
        $result      = $this->reporting()->run(Reporting::TEST_CONNECTION, self::KEY);
        self::assertSame('ok', $result?->check?->status);
        self::assertSame(1, $this->keyChecks());
        self::assertSame([], $this->reports(), 'nothing changed');

        self::assertSame('ok', $this->reporting()->lastCheck()?->status);
        self::assertSame('check', $this->reporting()->run(Reporting::REPORT_CHECK, self::KEY)?->event);
    }

    public function testAMissingKeyIsCheckedWithoutARequest(): void
    {
        $result = $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, '');

        self::assertSame('missing', $result?->check?->status);
        self::assertSame(0, $this->keyChecks());
        self::assertFalse($this->reports()[0]['api_key_configured']);
    }

    public function testOffMeansOff(): void
    {
        $result = $this->reporting(enabled: false)->run(Reporting::AFTER_SETTINGS_SAVED, self::KEY);

        self::assertSame('ok', $result?->check?->status, 'the key check runs anyway');
        self::assertSame([], $this->reports());
        self::assertNull($result->event);
    }

    public function testAFailedReportIsNotRetriedForAnHour(): void
    {
        $this->centerStatus = 503;
        self::assertFalse($this->reporting()->run(Reporting::ON_REQUEST, self::KEY)?->sent);

        $this->calls        = [];
        $this->centerStatus = 202;
        $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        self::assertSame([], $this->reports());
    }

    public function testWithoutASiteUrlTheStoredOneIsUsed(): void
    {
        // CLI without live_site and nothing stored: the key is checked, nothing reported.
        $result = $this->reporting(site: null)->run(Reporting::ON_REQUEST, self::KEY);
        self::assertSame('ok', $result?->check?->status);
        self::assertSame([], $this->reports());

        $this->storage->data['siteUrl'] = 'https://www.example.ch';
        $this->reporting(site: null)->run(Reporting::ON_REQUEST, self::KEY);
        self::assertSame('https://www.example.ch', $this->reports()[0]['site_url']);
    }

    public function testABrokenDatabaseNeverBreaksAnything(): void
    {
        $this->storage->failLoad = true;
        $result                  = $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, self::KEY);
        self::assertSame('ok', $result?->check?->status);
        self::assertSame([], $this->reports(), 'without its state the reporter would report on every request');
        self::assertNull($this->reporting()->lastCheck());

        $this->storage->failLoad = false;
        $this->storage->failSave = true;
        self::assertSame('installed', $this->reporting()->run(Reporting::ON_REQUEST, self::KEY)?->event);
    }

    public function testThePluginVersionComesFromTheManifest(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../plugin/supertext.xml');
        self::assertNotFalse($xml);
        self::assertSame((string) $xml->version, Reporting::pluginVersion(__DIR__ . '/../plugin/supertext.xml'));
        self::assertSame('0.0.0', Reporting::pluginVersion(__DIR__ . '/missing.xml'));
    }

    public function testAStateStoredByTheSdk02ReporterIsKept(): void
    {
        $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        // What the SDK 0.2 reporter stored (same JSON as Plugin Health's ReporterState).
        $this->storage->data = [
            'reporter' => [
                'reportedVersions'  => '0.2.0|6.1.4|' . PHP_VERSION,
                'reportedKeyStatus' => 'ok',
                'lastFailureAt'     => null,
                'keyCheck'          => ['status' => 'ok', 'checkedAt' => '2026-10-01T08:00:00Z', 'httpStatus' => 200, 'errorCode' => null, 'fingerprint' => hash('sha256', self::KEY)],
            ],
            'siteUrl' => 'https://www.example.ch',
        ];
        $before      = $this->storage->data;
        $this->calls = [];

        self::assertNull($this->reporting()->run(Reporting::ON_REQUEST, self::KEY)?->event, 'no new installed report after the update');
        self::assertSame([], $this->calls);
        self::assertSame($before, $this->storage->data, 'stored in the same format');
    }
}

final class MemoryStorage implements StateStorage
{
    /** @var array<string, mixed> */
    public array $data = [];

    public bool $failLoad = false;

    public bool $failSave = false;

    public function load(): array
    {
        if ($this->failLoad) {
            throw new \RuntimeException('database down');
        }

        return json_decode((string) json_encode($this->data), true);
    }

    public function save(array $data): void
    {
        if ($this->failSave) {
            throw new \RuntimeException('database down');
        }

        $this->data = $data;
    }
}
