<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

final readonly class UploadResult
{
    public function __construct(
        public string $fileId,
        public string $fileKey,
        public string $url,
        public int    $durationMs,
        public int    $accessType,
        public int    $fileSize,
        public ?int   $expiresIn = null,
        public array  $raw = [],
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fileId: (string)($data['file_id'] ?? ''),
            fileKey: (string)($data['file_key'] ?? ''),
            url: (string)($data['url'] ?? ''),
            durationMs: (int)($data['duration_ms'] ?? 0),
            accessType: (int)($data['access_type'] ?? 1),
            fileSize: (int)($data['file_size'] ?? 0),
            expiresIn: isset($data['url_expires_in']) ? (int)$data['url_expires_in'] : null,
            raw: $data,
        );
    }

    public function isPrivate(): bool
    {
        return $this->accessType === 2;
    }
}
