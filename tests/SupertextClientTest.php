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
    private function client(callable $handler, string $key = 'test-key', string $reviewUrl = '', string $version = ''): SupertextClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use ($handler): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return $handler($method, $url, $body) + ['headers' => []];
        };

        return new SupertextClient($key, 'https://api.test/v1', $transport, 10, 0.01, static function (): void {
        }, $reviewUrl, $version);
    }

    /** Answers every step of the protocol at once. */
    private static function fakeSupertext(string $method, string $url): array
    {
        return match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        };
    }

    /** @return array<string, list<string>> host => methods called there */
    private function callsByHost(): array
    {
        $hosts = [];
        foreach ($this->calls as $call) {
            $hosts[(string) parse_url($call['url'], PHP_URL_HOST)][] = $call['method'];
        }

        return $hosts;
    }

    public function testTranslationReviewGoesThroughTheReviewService(): void
    {
        $client = $this->client(self::fakeSupertext(...), reviewUrl: 'https://review.test/v1/');

        self::assertSame($client, $client->forReview(false));
        $review = $client->forReview(true);
        self::assertNotSame($client, $review);
        self::assertSame($review, $client->forReview(true), 'one review client');

        // German with review, French without.
        self::assertSame([0 => 'Hallo'], $review->translateSegments([['text' => 'Hello', 'html' => false]], 'de-CH', 'en'));
        self::assertSame([0 => 'Hallo'], $client->forReview(false)->translateSegments([['text' => 'Hello', 'html' => false]], 'fr-CH', 'en'));

        self::assertSame(
            ['review.test' => ['POST', 'GET', 'GET', 'DELETE'], 'api.test' => ['POST', 'GET', 'GET', 'DELETE']],
            $this->callsByHost(),
            'upload, status, download and delete through the review service for the review language only'
        );
        self::assertSame('https://review.test/v1/translate/ai/file', $this->calls[0]['url']);
        self::assertSame('Supertext-Auth-Key test-key', $this->calls[0]['headers']['Authorization'], 'same key');

        $this->calls = [];
        $client->checkApiKey();
        $review->checkApiKey();
        self::assertSame(['api.test' => ['GET'], 'review.test' => ['GET']], $this->callsByHost());
    }

    public function testTranslationReviewIsOffWithoutAReviewUrl(): void
    {
        $client = $this->client(self::fakeSupertext(...));
        self::assertSame($client, $client->forReview(true), 'empty review URL: review off for every language');

        $client = $this->client(self::fakeSupertext(...), reviewUrl: 'https://api.test/v1/');
        self::assertSame($client, $client->forReview(true), 'same URL as the API: no second client');
    }

    public function testTheReviewServiceIsTheDefaultSetting(): void
    {
        $xml = simplexml_load_file(\dirname(__DIR__) . '/plugin/supertext.xml');
        self::assertNotFalse($xml);
        $field = $xml->xpath('//field[@name="review_url"]');
        self::assertNotEmpty($field);
        self::assertSame(SupertextClient::REVIEW_URL, (string) $field[0]['default']);
        self::assertSame('https://localizationreview-production.up.railway.app/v1/', SupertextClient::REVIEW_URL);
    }

    public function testTheUserAgentNamesThePluginVersion(): void
    {
        $client = $this->client(self::fakeSupertext(...), reviewUrl: 'https://review.test/v1/', version: '1.2.3');
        $client->validateApiKey();
        $client->forReview(true)->validateApiKey();

        foreach ($this->calls as $call) {
            self::assertStringContainsString('Joomla-Supertext-Translation/1.2.3', $call['headers']['User-Agent'] ?? '');
        }
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

        $html = $client->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en', 'more');

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

    public function testTranslatesSegmentsAndToleratesMissingOnes(): void
    {
        $client = $this->client(fn (string $method, string $url) => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hallo<br>Welt</div><div data-st-id="1"><p>Ein <b>Text</b></p></div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        });

        $result = $client->translateSegments([
            3 => ['text' => "Hello\nworld", 'html' => false],
            5 => ['text' => '<p>A <b>text</b></p>', 'html' => true],
            7 => ['text' => '   ', 'html' => false],
            9 => ['text' => 'Lost', 'html' => false],
        ], 'de-CH', 'en');

        self::assertSame([3 => "Hallo\nWelt", 5 => '<p>Ein <b>Text</b></p>'], $result);
        self::assertStringContainsString('<div data-st-id="0">Hello<br>world</div>', (string) $this->calls[0]['body']);
        self::assertStringContainsString('<div data-st-id="2">Lost</div>', (string) $this->calls[0]['body']);
    }

    public function testSplitsLargeContentIntoSeveralDocuments(): void
    {
        $posts = 0;
        $client = $this->client(function (string $method, string $url) use (&$posts) {
            return match (true) {
                $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f' . ++$posts . '"}'],
                str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
                str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">x</div>'],
                default                             => ['status' => 200, 'body' => '{}'],
            };
        });

        $client->translateSegments([['text' => str_repeat('a', 8), 'html' => false], ['text' => str_repeat('b', 8), 'html' => false]], 'de-DE', '', 'default', 10);

        self::assertSame(2, $posts);
    }

    public function testReportsAnUnsupportedLanguagePair(): void
    {
        $client = $this->client(fn () => ['status' => 400, 'body' => '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx-YY"}']);

        try {
            $client->translateDocument('<p>x</p>', 'xx-YY', 'en');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('invalid_language_pair', $e->reason);
            self::assertSame(['en', 'xx-YY'], $e->args);
        }
    }

    public function testSendsTheCodesAsGiven(): void
    {
        $client = $this->client(fn (string $method, string $url) => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hola</div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        });

        $client->translateDocument('<div data-st-id="0">Hello</div>', 'es', 'en');

        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nes\r\n", (string) $this->calls[0]['body']);
    }

    public function testChecksTheApiKeyWithoutThrowing(): void
    {
        self::assertSame('ok', $this->client(fn () => ['status' => 200, 'body' => '{}'])->checkApiKey()->status);
        self::assertSame('invalid', $this->client(fn () => ['status' => 401, 'body' => '{"error_code":"UNAUTHORIZED"}'])->checkApiKey()->status);
        self::assertSame('error', $this->client(fn () => ['status' => 404, 'body' => '{}'])->checkApiKey()->status);
        self::assertSame('missing', $this->client(fn () => ['status' => 200, 'body' => '{}'], '')->checkApiKey()->status);
        self::assertSame('unreachable', $this->client(static function (): array {
            throw new \RuntimeException('connection refused');
        })->checkApiKey()->status);
    }

    public function testReportsAToneSupertextDoesNotOffer(): void
    {
        $client = $this->client(fn () => ['status' => 400, 'body' => '{"error_code":"FEATURE_ERROR","feature":"politeness","source_lang":"de","target_lang":"en-US"}']);

        try {
            $client->translateDocument('<p>x</p>', 'en-US', 'de', 'more');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_feature', $e->reason);
        }
    }

    public function testEnvironments(): void
    {
        self::assertSame(SupertextClient::LIVE, SupertextClient::baseUrlFor('live'));
        self::assertSame(SupertextClient::STAGING, SupertextClient::baseUrlFor('staging'));
        self::assertSame('http://127.0.0.1:8765/v1/', SupertextClient::baseUrlFor('live', 'http://127.0.0.1:8765/v1/'));
    }
}
