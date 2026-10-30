<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

final readonly class FileInfo
{
    public function __construct(
        public string  $fileId,
        public string  $appId,
        public int     $accessType,
        public string  $fileKey,
        public string  $fileName,
        public string  $fileExt,
        public string  $mimeType,
        public int     $fileSize,
        public string  $etag,
        public int     $uploadMode,
        public int     $chunkCount,
        public int     $durationMs,
        public string  $url,
        public int     $status,
        public ?string $createdAt = null,
        public array   $raw = [],
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fileId: (string)($data['file_id'] ?? ''),
            appId: (string)($data['app_id'] ?? ''),
            accessType: (int)($data['access_type'] ?? 1),
            fileKey: (string)($data['file_key'] ?? ''),
            fileName: (string)($data['file_name'] ?? ''),
            fileExt: (string)($data['file_ext'] ?? ''),
            mimeType: (string)($data['mime_type'] ?? ''),
            fileSize: (int)($data['file_size'] ?? 0),
            etag: (string)($data['etag'] ?? ''),
            uploadMode: (int)($data['upload_mode'] ?? 1),
            chunkCount: (int)($data['chunk_count'] ?? 1),
            durationMs: (int)($data['duration_ms'] ?? 0),
            url: (string)($data['url'] ?? ''),
            status: (int)($data['status'] ?? 1),
            createdAt: isset($data['created_at']) ? (string)$data['created_at'] : null,
            raw: $data,
        );
    }

    public function isChunked(): bool
    {
        return $this->uploadMode === 2;
    }
}
