<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

/**
 * 删除结果。
 * 正常删除：deleted=true / records=已清理的记录数
 * 试运行（dryRun=true）：只回显文件信息，deleted=false、fileSize/accessType/createdAt 有值
 */
final readonly class DeleteResult
{
    public function __construct(
        public string  $fileKey,
        public bool    $dryRun,
        public bool    $deleted,
        public bool    $alreadyAbsent,
        public int     $records,
        public ?int    $fileSize,
        public ?int    $accessType,
        public ?string $createdAt,
        public array   $raw = [],
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fileKey: (string)($data['file_key'] ?? ''),
            dryRun: (bool)($data['dry_run'] ?? false),
            deleted: (bool)($data['deleted'] ?? false),
            alreadyAbsent: (bool)($data['already_absent'] ?? false),
            records: (int)($data['records'] ?? 0),
            fileSize: isset($data['file_size']) ? (int)$data['file_size'] : null,
            accessType: isset($data['access_type']) ? (int)$data['access_type'] : null,
            createdAt: isset($data['created_at']) ? (string)$data['created_at'] : null,
            raw: $data,
        );
    }

    /**
     * 七牛对象本来就不存在（记录仍被清掉了）。
     */
    public function wasAlreadyAbsent(): bool
    {
        return $this->alreadyAbsent;
    }
}
