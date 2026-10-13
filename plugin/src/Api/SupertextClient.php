<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

use Supertext\Plugin\System\Supertext\Sdk\Client;
use Supertext\Plugin\System\Supertext\Sdk\HtmlDocument;
use Supertext\Plugin\System\Supertext\Sdk\Segment;
use Supertext\Plugin\System\Supertext\Sdk\Supertext;
use Supertext\Plugin\System\Supertext\Sdk\SupertextException as SdkException;

/**
 * Supertext AI file translation API v1 (https://api.supertext.com/v1/), through the
 * Supertext PHP SDK bundled in src/Sdk (namespace Supertext\Plugin\System\Supertext\Sdk).
 *
 * The SDK does the protocol (upload one HTML document, poll its status, download the
 * translation, delete the file), the auth header, HTTP 429 retries, language codes and
 * the data-st-id document. This class keeps the plugin's interface: HTTP goes through a
 * transport callable (Joomla's HTTP client, or a fake in tests), and SDK errors come out
 * as this plugin's SupertextException with a reason Service\Messages translates.
 *
 * Has no Joomla dependencies.
 */
final class SupertextClient
{
    public const LIVE = Supertext::LIVE_BASE_URL;
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    /** Stay well below the API's 1,000,000 character limit per document. */
    public const MAX_DOCUMENT_CHARACTERS = Supertext::DEFAULT_MAX_CHARACTERS;

    /** SDK error code → [plugin reason, English message]. Service\Messages::of() translates the reason (PLG_SYSTEM_SUPERTEXT_API_<REASON>). */
    private const REASONS = [
        SdkException::MISSING_API_KEY => ['no_api_key', 'No Supertext API key is configured. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).'],
        SdkException::AUTHENTICATION_FAILURE => ['auth_failed', 'Authentication failed. Please check the Supertext API key. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).'],
        SdkException::INVALID_LANGUAGE_PAIR => ['invalid_language_pair', 'Supertext does not support this language pair (%s → %s).'],
        SdkException::NOT_FOUND => ['not_found', 'The requested Supertext resource was not found.'],
        SdkException::PAYLOAD_TOO_LARGE => ['too_large', 'The content is too large for Supertext to translate in one go.'],
        SdkException::TOO_MANY_REQUESTS => ['rate_limited', 'Too many requests to Supertext. Please try again shortly.'],
        SdkException::SERVICE_UNAVAILABLE => ['unavailable', 'The Supertext service is currently unavailable.'],
        SdkException::UNEXPECTED_STATUS => ['http_error', 'Supertext answered with HTTP %s.'],
        SdkException::TRANSPORT_ERROR => ['unreachable', 'Could not reach Supertext: %s'],
        SdkException::NO_FILE_ID => ['no_file_id', 'Supertext did not return a file id.'],
        SdkException::TRANSLATION_ERROR => ['translation_failed', 'Supertext could not translate the document.'],
        SdkException::QUOTA_EXCEEDED => ['limit_exceeded', 'Your Supertext translation limit is exceeded. Please upgrade your subscription.'],
        SdkException::FILE_DELETED => ['deleted', 'The document was deleted at Supertext before it could be downloaded.'],
        SdkException::TIMEOUT => ['timeout', 'Timed out waiting for the Supertext translation.'],
        SdkException::INCOMPLETE_RESPONSE => ['empty', 'The translated document was empty.'],
    ];

    private readonly Client $sdk;
    private readonly string $apiKey;

    /**
     * @param callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string, headers: array<string, string>} $transport
     * @param callable(float $seconds): void|null $sleep tests: the SDK's clock then counts the slept time
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        callable $transport,
        private readonly int $pollTimeout = 180,
        float $pollInterval = 2.0,
        ?callable $sleep = null,
    ) {
        $this->apiKey = self::normalizeKey($apiKey);

        $elapsed = 0.0;
        $sdkSleep = $sleep === null ? null : static function (float $seconds) use ($sleep, &$elapsed): void {
            $elapsed += $seconds;
            $sleep($seconds);
        };
        $clock = $sleep === null ? null : static function () use (&$elapsed): float {
            return $elapsed;
        };

        $this->sdk = new Client(
            $this->apiKey,
            baseUrl: $baseUrl !== '' ? $baseUrl : self::LIVE,
            pollInterval: $pollInterval,
            timeout: (float) $pollTimeout,
            requestTimeout: 60.0,
            userAgent: 'Joomla-Supertext-Translation',
            transport: new CallableTransport($transport),
            sleep: $sdkSleep,
            clock: $clock,
        );
    }

    /** Supertext shows the key as "Supertext-Auth-Key <key>"; accept it with or without that prefix. */
    public static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/^Supertext-Auth-Key\s+/i', '', trim($key));
    }

    public static function baseUrlFor(string $environment, string $customEndpoint = ''): string
    {
        if (trim($customEndpoint) !== '') {
            return trim($customEndpoint);
        }

        return match ($environment) {
            'staging' => self::STAGING,
            'testing' => self::TESTING,
            default   => self::LIVE,
        };
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Translates a complete HTML document and returns the translated HTML.
     *
     * @param string $targetLanguage BCP-47 code, e.g. "de-CH"; a bare language gets its default region ("de" → "de-DE")
     * @param string $sourceLanguage any form ("en-GB"); only the primary subtag is sent. Empty: auto-detect.
     * @param string $politeness     "default", "more" (formal) or "less" (informal)
     */
    public function translateDocument(string $html, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): string
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit($this->pollTimeout + 60);
        }

        return $this->call(fn (): string => $this->sdk->translateHtml($html, $targetLanguage, $sourceLanguage !== '' ? $sourceLanguage : null, $politeness));
    }

    /**
     * Translates keyed segments, packed into as few documents as the size limit allows.
     * A segment missing from Supertext's answer is left out of the result.
     *
     * @param array<int, array{text: string, html: bool}> $segments
     * @return array<int, string> segment key => translation
     */
    public function translateSegments(array $segments, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default', int $maxCharacters = self::MAX_DOCUMENT_CHARACTERS): array
    {
        $toSend = [];
        foreach ($segments as $key => $segment) {
            $item = $segment['html'] ? Segment::html($segment['text']) : Segment::text($segment['text']);
            if (!$item->isBlank()) {
                $toSend[$key] = $item;
            }
        }

        $result = [];
        foreach (Client::batches($toSend, $maxCharacters) as $batch) {
            $keys = array_keys($batch);
            $list = array_values($batch);
            $html = $this->translateDocument(HtmlDocument::build($list), $targetLanguage, $sourceLanguage, $politeness);
            foreach (HtmlDocument::parse($html, $list) as $index => $text) {
                if ($text !== null) {
                    $result[$keys[$index]] = $text;
                }
            }
        }

        return $result;
    }

    /** Cost-free check that the key is accepted. */
    public function validateApiKey(): void
    {
        $this->call(fn () => $this->sdk->validateApiKey());
    }

    /** Seconds to wait before retry $attempt (0-based): Retry-After if sent, else 1, 2, 4, 8 plus jitter. */
    public static function retryDelay(int $attempt, string $retryAfter = ''): float
    {
        return Supertext::retryDelayMs($attempt, $retryAfter !== '' ? $retryAfter : null) / 1000;
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (SdkException $e) {
            throw self::fromSdk($e);
        }
    }

    private static function fromSdk(SdkException $e): SupertextException
    {
        [$reason, $message] = self::REASONS[$e->errorCode] ?? ['', $e->getMessage()];
        $detail = (string) $e->detail;
        $args = match ($e->errorCode) {
            SdkException::UNEXPECTED_STATUS => [(int) $e->status],
            SdkException::INVALID_LANGUAGE_PAIR => [(string) $e->sourceLang ?: '?', (string) $e->targetLang ?: '?'],
            SdkException::TRANSPORT_ERROR => [$detail],
            default => [],
        };
        if ($e->errorCode === SdkException::TRANSPORT_ERROR) {
            $detail = ''; // already in the message
        }

        return SupertextException::because($reason, $message, $args, (int) $e->status, $detail, $e);
    }
}
