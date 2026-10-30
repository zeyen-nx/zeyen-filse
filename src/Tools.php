<?php


declare(strict_types=1);

namespace Zeyen\Filse;

use Zeyen\Filse\Upload\AppKeyHashResult;
use Zeyen\Filse\Upload\QiniuKeyResult;

/**
 * 运维工具接口。
 * appKeyHash 无需认证；
 * encrypt / decrypt 需要 app 凭据（X-App-Id + X-App-Key）或 X-Admin-Token。
 */
final class Tools
{
    public function __construct(private readonly Client $client)
    {
    }

    public function appKeyHash(string $appKey): AppKeyHashResult
    {
        $data = $this->client->toolsRequest('/api/v1/tools/app-key-hash', ['app_key' => $appKey], false);

        return AppKeyHashResult::fromArray($data);
    }

    public function encryptQiniuKeys(?string $accessKey = null, ?string $secretKey = null): QiniuKeyResult
    {
        $payload = [];
        if ($accessKey !== null && $accessKey !== '') {
            $payload['qiniu_access_key'] = $accessKey;
        }
        if ($secretKey !== null && $secretKey !== '') {
            $payload['qiniu_secret_key'] = $secretKey;
        }

        $data = $this->client->toolsRequest('/api/v1/tools/qiniu/encrypt', $payload, true);

        return QiniuKeyResult::fromArray($data);
    }

    public function decryptQiniuKeys(?string $accessKeyCipher = null, ?string $secretKeyCipher = null): QiniuKeyResult
    {
        $payload = [];
        if ($accessKeyCipher !== null && $accessKeyCipher !== '') {
            $payload['qiniu_access_key_cipher'] = $accessKeyCipher;
        }
        if ($secretKeyCipher !== null && $secretKeyCipher !== '') {
            $payload['qiniu_secret_key_cipher'] = $secretKeyCipher;
        }

        $data = $this->client->toolsRequest('/api/v1/tools/qiniu/decrypt', $payload, true);

        return QiniuKeyResult::fromArray($data);
    }
}
