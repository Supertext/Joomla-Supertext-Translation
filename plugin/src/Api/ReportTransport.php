<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

use Supertext\Plugin\System\Supertext\PluginHealth\Http\HttpRequest;
use Supertext\Plugin\System\Supertext\PluginHealth\Http\HttpResponse;
use Supertext\Plugin\System\Supertext\PluginHealth\Http\HttpTransport;
use Supertext\Plugin\System\Supertext\PluginHealth\Http\TransportException;
use Supertext\Plugin\System\Supertext\Sdk\Http\HttpRequest as SdkHttpRequest;
use Supertext\Plugin\System\Supertext\Sdk\Http\HttpTransport as SdkHttpTransport;

/**
 * Sends Supertext Plugin Health's version reports through the plugin's SDK transport
 * (CallableTransport: Joomla's HTTP client, a fake in tests). Plugin Health has its own HTTP
 * types; this only converts them. No Joomla dependency.
 */
final class ReportTransport implements HttpTransport
{
    public function __construct(private readonly SdkHttpTransport $transport)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->transport->send(new SdkHttpRequest($request->method, $request->url, $request->headers, $request->body, $request->timeout));
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new HttpResponse($response->status, $response->body, $response->headers);
    }
}
