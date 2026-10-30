<?php


declare(strict_types=1);

namespace Zeyen\Filse\Http;

final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param resource|null         $fileStream
     */
    public function __construct(
        public readonly string  $method,
        public readonly string  $url,
        public readonly array   $headers = [],
        public readonly ?string $body = null,
        public readonly ?string $filePath = null,
        public readonly mixed   $fileStream = null,
        public readonly ?int    $contentLength = null,
        public readonly float   $timeout = 300.0,
        public readonly float   $connectTimeout = 10.0,
        public readonly bool    $verifySsl = true,
    )
    {
    }
}
