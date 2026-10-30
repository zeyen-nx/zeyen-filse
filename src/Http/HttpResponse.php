<?php


declare(strict_types=1);

namespace Zeyen\Filse\Http;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers 键名统一小写
     */
    public function __construct(
        public readonly int    $status,
        public readonly string $body,
        public readonly array  $headers = [],
    )
    {
    }

    public function json(): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
