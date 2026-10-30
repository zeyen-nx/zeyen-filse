<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

/**
 * 同时承载加密（cipher）与解密（明文）两种结果，未涉及的字段为 null。
 */
final readonly class QiniuKeyResult
{
    public function __construct(
        public ?string $accessKey = null,
        public ?string $secretKey = null,
        public ?string $accessKeyCipher = null,
        public ?string $secretKeyCipher = null,
        public ?string $accessKeyHint = null,
        public ?string $secretKeyHint = null,
        public bool    $alreadyEncrypted = false,
        public array   $raw = [],
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accessKey: isset($data['qiniu_access_key']) ? (string)$data['qiniu_access_key'] : null,
            secretKey: isset($data['qiniu_secret_key']) ? (string)$data['qiniu_secret_key'] : null,
            accessKeyCipher: isset($data['qiniu_access_key_cipher']) ? (string)$data['qiniu_access_key_cipher'] : null,
            secretKeyCipher: isset($data['qiniu_secret_key_cipher']) ? (string)$data['qiniu_secret_key_cipher'] : null,
            accessKeyHint: isset($data['qiniu_access_key_hint']) ? (string)$data['qiniu_access_key_hint'] : null,
            secretKeyHint: isset($data['qiniu_secret_key_hint']) ? (string)$data['qiniu_secret_key_hint'] : null,
            alreadyEncrypted: (bool)($data['already_encrypted'] ?? false),
            raw: $data,
        );
    }
}
