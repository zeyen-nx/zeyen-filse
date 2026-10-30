<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

final readonly class AppKeyHashResult
{
    public function __construct(
        public string $appKeyHash,
        public string $appKeyPrefix,
        public int    $appKeyLength,
        public string $algorithm,
        public array  $raw = [],
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            appKeyHash: (string)($data['app_key_hash'] ?? ''),
            appKeyPrefix: (string)($data['app_key_prefix'] ?? ''),
            appKeyLength: (int)($data['app_key_length'] ?? 0),
            algorithm: (string)($data['algorithm'] ?? 'sha256'),
            raw: $data,
        );
    }
}
