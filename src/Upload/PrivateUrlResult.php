<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

final readonly class PrivateUrlResult
{
    public function __construct(
        public string $fileKey,
        public string $url,
        public int    $expiresIn,
        public array  $raw = [],
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fileKey: (string)($data['file_key'] ?? ''),
            url: (string)($data['url'] ?? ''),
            expiresIn: (int)($data['expires_in'] ?? 0),
            raw: $data,
        );
    }
}
