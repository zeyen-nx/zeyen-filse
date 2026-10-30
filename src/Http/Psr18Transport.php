<?php


declare(strict_types=1);

namespace Zeyen\Filse\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Zeyen\Filse\Exception\ConfigException;

/**
 * 可选传输层：把框架自带的 PSR-18 客户端接进来（Guzzle / Symfony HttpClient / Hyperf 等）。
 * 注意：PSR-18 不支持流式请求体，流式上传时文件内容会被读入内存；
 * 大文件请使用默认的 CurlTransport。超时需在 PSR-18 客户端自身配置。
 */
final class Psr18Transport implements TransportInterface
{
    public function __construct(
        private readonly ClientInterface         $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface  $streamFactory,
    )
    {
        if (!interface_exists(ClientInterface::class)) {
            throw new ConfigException('未安装 psr/http-client，请先 composer require psr/http-client psr/http-factory');
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        $payload = $request->body;
        if ($request->filePath !== null) {
            $payload = (string)file_get_contents($request->filePath);
        } elseif ($request->fileStream !== null) {
            $payload = (string)stream_get_contents($request->fileStream);
        }
        if ($payload !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($payload));
        }

        $response = $this->client->sendRequest($psrRequest);

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return new HttpResponse($response->getStatusCode(), (string)$response->getBody(), $headers);
    }
}
