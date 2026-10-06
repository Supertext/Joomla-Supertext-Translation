<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

/**
 * Supertext AI file translation API v1 (https://api.supertext.com/v1/).
 *
 * Same protocol as the Supertext WordPress, Drupal, TYPO3, Strapi and Payload plugins:
 * submit one HTML document, poll its status, download the translation, delete the file.
 *
 * Has no Joomla dependencies: HTTP goes through a transport callable so it can be tested
 * with a fake and used outside Joomla.
 */
final class SupertextClient
{
    public const LIVE = 'https://api.supertext.com/v1/';
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    /** Stay well below the API's 1,000,000 character limit per document. */
    public const MAX_DOCUMENT_CHARACTERS = 900000;

    /** Retries after HTTP 429: the API limits requests per second per key. */
    public const RATE_LIMIT_RETRIES = 4;

    private string $apiKey;
    private string $baseUrl;

    /** @var callable(string, string, array<string, string>, ?string): array{status: int, body: string, headers: array<string, string>} */
    private $transport;

    /** @var callable(float): void */
    private $sleep;

    /**
     * @param callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string, headers: array<string, string>} $transport
     * @param callable(float $seconds): void|null $sleep
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        callable $transport,
        private readonly int $pollTimeout = 180,
        private readonly float $pollInterval = 2.0,
        ?callable $sleep = null,
    ) {
        $this->apiKey    = self::normalizeKey($apiKey);
        $this->baseUrl   = rtrim($baseUrl !== '' ? $baseUrl : self::LIVE, '/') . '/';
        $this->transport = $transport;
        $this->sleep     = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1000000));
        };
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
     * @param string $targetLanguage BCP-47 code, e.g. "de-CH"
     * @param string $sourceLanguage any form ("en-GB"); only the primary subtag is sent. Empty: auto-detect.
     * @param string $politeness     "default", "more" (formal) or "less" (informal)
     */
    public function translateDocument(string $html, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): string
    {
        $fileId = $this->submit($html, $targetLanguage, $sourceLanguage, $politeness);

        try {
            $this->waitUntilDone($fileId);

            return $this->download($fileId);
        } finally {
            try {
                $this->request('DELETE', 'translate/ai/file/' . rawurlencode($fileId));
            } catch (\Throwable) {
                // Files expire after 24 hours anyway.
            }
        }
    }

    /** Cost-free check that the key is accepted. */
    public function validateApiKey(): void
    {
        $this->request('GET', 'features');
    }

    private function submit(string $html, string $targetLanguage, string $sourceLanguage, string $politeness): string
    {
        $fields = ['target_lang' => $targetLanguage];
        $source = strtolower((string) strtok($sourceLanguage, '-_'));

        if ($source !== '') {
            // A full tag such as "en-GB" as source is rejected with INVALID_LANGUAGE_PAIR.
            $fields['source_lang'] = $source;
        }

        if (\in_array($politeness, ['more', 'less'], true)) {
            $fields['politeness'] = $politeness;
        }

        $boundary = '----supertext' . bin2hex(random_bytes(12));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        // The part's Content-Type must be exactly "text/html" (no charset), or the API answers 415.
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"content.html\"\r\n"
            . "Content-Type: text/html\r\n\r\n{$html}\r\n--{$boundary}--\r\n";

        $data   = $this->json($this->request('POST', 'translate/ai/file', $body, 'multipart/form-data; boundary=' . $boundary));
        $fileId = (string) ($data['file_id'] ?? '');

        if ($fileId === '') {
            throw new SupertextException('Supertext did not return a file id.');
        }

        return $fileId;
    }

    private function waitUntilDone(string $fileId): void
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit($this->pollTimeout + 60);
        }

        $deadline = microtime(true) + $this->pollTimeout;

        do {
            $status = (string) ($this->json($this->request('GET', 'translate/ai/file/' . rawurlencode($fileId) . '/status'))['status'] ?? '');

            switch ($status) {
                case 'done':
                    return;
                case 'error':
                    throw new SupertextException('Supertext could not translate the document.');
                case 'limit_exceeded':
                    throw new SupertextException('Your Supertext translation limit is exceeded. Please upgrade your subscription.');
                case 'deleted':
                    throw new SupertextException('The document was deleted at Supertext before it could be downloaded.');
            }

            ($this->sleep)($this->pollInterval);
        } while (microtime(true) < $deadline);

        throw new SupertextException('Timed out waiting for the Supertext translation.');
    }

    private function download(string $fileId): string
    {
        $body = $this->request('GET', 'translate/ai/file/' . rawurlencode($fileId) . '/translation')['body'];

        if (trim($body) === '') {
            throw new SupertextException('The translated document was empty.');
        }

        return $body;
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private function request(string $method, string $path, ?string $body = null, string $contentType = ''): array
    {
        if ($this->apiKey === '') {
            throw new SupertextException('No Supertext API key is configured. Generate one at https://www.supertext.com/en/integrations/api (requires the Admin role).');
        }

        $headers = [
            'Accept'        => 'application/json',
            'Authorization' => 'Supertext-Auth-Key ' . $this->apiKey,
        ];

        if ($contentType !== '') {
            $headers['Content-Type'] = $contentType;
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = ($this->transport)($method, $this->baseUrl . $path, $headers, $body);
            } catch (\Throwable $e) {
                throw new SupertextException('Could not reach Supertext: ' . $e->getMessage(), 0, $e);
            }

            if ($response['status'] !== 429 || $attempt >= self::RATE_LIMIT_RETRIES) {
                break;
            }

            ($this->sleep)(self::retryDelay($attempt, self::header($response['headers'], 'Retry-After')));
        }

        $code = $response['status'];

        if ($code >= 200 && $code < 300) {
            return $response;
        }

        $message = match (true) {
            $code === 401, $code === 403 => 'Authentication failed. Please check the Supertext API key. A new key can be generated at https://www.supertext.com/en/integrations/api (requires the Admin role).',
            $code === 404                => 'The requested Supertext resource was not found.',
            $code === 413                => 'The content is too large for Supertext to translate in one go.',
            $code === 429                => 'Too many requests to Supertext. Please try again shortly.',
            $code >= 500                 => 'The Supertext service is currently unavailable.',
            default                      => sprintf('Supertext answered with HTTP %d.', $code),
        };

        $detail = trim(strip_tags($response['body']));

        if ($detail !== '') {
            $message .= ' (' . mb_substr($detail, 0, 200) . ')';
        }

        throw new SupertextException($message, $code);
    }

    /** Seconds to wait before retry $attempt (0-based): Retry-After if sent, else 1, 2, 4, 8 plus jitter. */
    public static function retryDelay(int $attempt, string $retryAfter = ''): float
    {
        if ($retryAfter !== '' && is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return min(30.0, (float) $retryAfter);
        }

        return (2 ** $attempt) + random_int(0, 250) / 1000;
    }

    /** @param array<string, string> $headers */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return trim($value);
            }
        }

        return '';
    }

    /** @param array{status: int, body: string, headers: array<string, string>} $response */
    private function json(array $response): array
    {
        $data = json_decode($response['body'], true);

        return \is_array($data) ? $data : [];
    }
}
