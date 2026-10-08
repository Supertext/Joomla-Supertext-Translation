<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\TestCase;
use Supertext\Plugin\System\Supertext\Api\SupertextClient;
use Supertext\Plugin\System\Supertext\Api\SupertextException;

final class SupertextClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /** @param callable(string, string, ?string): array{status: int, body: string, headers?: array<string, string>} $handler */
    private function client(callable $handler, string $key = 'test-key'): SupertextClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use ($handler): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return $handler($method, $url, $body) + ['headers' => []];
        };

        return new SupertextClient($key, 'https://api.test/v1', $transport, 10, 0.01, static function (): void {
        });
    }

    public function testRunsTheFullProtocol(): void
    {
        $polls  = 0;
        $client = $this->client(function (string $method, string $url) use (&$polls): array {
            return match (true) {
                $method === 'POST'                    => ['status' => 200, 'body' => '{"file_id":"f1"}'],
                str_ends_with($url, '/status')        => ['status' => 200, 'body' => json_encode(['status' => ++$polls < 2 ? 'running' : 'done'])],
                str_ends_with($url, '/translation')   => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>'],
                default                               => ['status' => 200, 'body' => '{}'],
            };
        });

        $html = $client->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en-GB', 'more');

        self::assertStringContainsString('Hallo', $html);
        self::assertSame(
            ['POST translate/ai/file', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/translation', 'DELETE translate/ai/file/f1'],
            array_map(static fn (array $c): string => $c['method'] . ' ' . str_replace('https://api.test/v1/', '', $c['url']), $this->calls)
        );

        $post = $this->calls[0];
        self::assertSame('Supertext-Auth-Key test-key', $post['headers']['Authorization']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $post['headers']['Content-Type']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH\r\n", $post['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", $post['body']);
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore\r\n", $post['body']);
        self::assertStringContainsString("filename=\"content.html\"\r\nContent-Type: text/html\r\n\r\n<div", $post['body']);
    }

    public function testAcceptsTheKeyWithItsPrefix(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '  Supertext-Auth-Key abc+/= ');
        $client->validateApiKey();

        self::assertSame('Supertext-Auth-Key abc+/=', $this->calls[0]['headers']['Authorization']);
    }

    public function testRetriesWhenRateLimited(): void
    {
        $limited = 2;
        $client  = $this->client(function () use (&$limited): array {
            return $limited-- > 0 ? ['status' => 429, 'body' => 'slow down'] : ['status' => 200, 'body' => '{}'];
        });
        $client->validateApiKey();

        self::assertCount(3, $this->calls);
    }

    public function testGivesUpAfterFourRetries(): void
    {
        $client = $this->client(fn () => ['status' => 429, 'body' => 'slow down']);

        try {
            $client->validateApiKey();
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('Too many requests', $e->getMessage());
        }

        self::assertCount(5, $this->calls);
    }

    public function testHonoursRetryAfter(): void
    {
        self::assertSame(3.0, SupertextClient::retryDelay(0, '3'));
        self::assertGreaterThanOrEqual(2.0, SupertextClient::retryDelay(1));
    }

    public function testGivesAReasonTheCmsCanTranslate(): void
    {
        $client = $this->client(fn () => ['status' => 418, 'body' => 'teapot']);

        try {
            $client->validateApiKey();
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('http_error', $e->reason);
            self::assertSame([418], $e->args);
            self::assertSame('teapot', $e->detail);
            self::assertSame('Supertext answered with HTTP 418. (teapot)', $e->getMessage());
        }
    }

    public function testExplainsAuthenticationErrors(): void
    {
        $client = $this->client(fn () => ['status' => 401, 'body' => '{"detail":"bad key"}']);

        $this->expectException(SupertextException::class);
        $this->expectExceptionMessageMatches('/Authentication failed.*bad key/');
        $client->translateDocument('<p>x</p>', 'fr-FR');
    }

    public function testStopsOnLimitExceededAndCleansUp(): void
    {
        $client = $this->client(function (string $method, string $url): array {
            return match (true) {
                $method === 'POST'             => ['status' => 200, 'body' => '{"file_id":"f2"}'],
                str_ends_with($url, '/status') => ['status' => 200, 'body' => '{"status":"limit_exceeded"}'],
                default                        => ['status' => 200, 'body' => '{}'],
            };
        });

        try {
            $client->translateDocument('<p>x</p>', 'fr-FR');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('limit is exceeded', $e->getMessage());
            self::assertSame('limit_exceeded', $e->reason);
        }

        self::assertSame('DELETE', end($this->calls)['method']);
    }

    public function testRefusesToRunWithoutKey(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '');

        self::assertFalse($client->hasApiKey());
        $this->expectException(SupertextException::class);
        $client->validateApiKey();
    }

    public function testEnvironments(): void
    {
        self::assertSame(SupertextClient::LIVE, SupertextClient::baseUrlFor('live'));
        self::assertSame(SupertextClient::STAGING, SupertextClient::baseUrlFor('staging'));
        self::assertSame('http://127.0.0.1:8765/v1/', SupertextClient::baseUrlFor('live', 'http://127.0.0.1:8765/v1/'));
    }
}
