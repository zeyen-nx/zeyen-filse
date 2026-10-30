<?php


declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Zeyen\Filse\Client;
use Zeyen\Filse\Exception\ApiException;
use Zeyen\Filse\Exception\TransportException;

// 方式一：从 .env 读取（环境变量优先）
$client = Client::fromEnvFile(__DIR__ . '/../.env');

// 方式二：从系统环境变量读取（Laravel/Symfony 直接用这个）
// $client = Client::fromEnv();

// 方式三：显式配置
// $client = Client::create([
//     'base_url' => 'https://api.example.com',
//     'app_id' => 'demo_public',
//     'app_key' => 'demo_key_123456',
//     'origin' => 'https://example.com',
//     'timeout' => 300,
// ]);

try {
    echo "health: " . json_encode($client->health(), JSON_UNESCAPED_UNICODE) . PHP_EOL;

    // 上传：默认走原始流，大文件不占内存
    $result = $client->upload(__DIR__ . '/photo.jpg')
        ->fileName('photo.jpg')
        ->path('album/2026')
        ->send();

    printf("file_key=%s%surl=%s%ssize=%d time=%dms%s",
        $result->fileKey, PHP_EOL, $result->url, PHP_EOL, $result->fileSize, $result->durationMs, PHP_EOL);

    // 小文件走表单通道，并附带业务字段
    $small = $client->upload(__DIR__ . '/note.txt')->asForm()->send();
    echo "form upload: {$small->fileKey}\n";

    // 不传文件名（服务端按文件头自动命名）
    $auto = $client->upload(__DIR__ . '/whatever.bin')->send();
    echo "auto named: {$auto->fileKey}\n";

    // 私有文件换签名链接
    // $link = $client->privateUrl($result->fileKey, 600);
    // echo $link->url, PHP_EOL;

    // 文件列表
    $page = $client->files(1, 5);
    printf("total=%d 当前页 %d 条%s", $page->total, count($page->items), PHP_EOL);
    foreach ($page->items as $item) {
        printf("  %s  %s  %d bytes  %s%s", $item->fileKey, $item->mimeType, $item->fileSize, $item->createdAt ?? '-', PHP_EOL);
    }

    // 运维工具
    $hash = $client->tools()->appKeyHash('demo_key_123456');
    echo "app_key_hash: {$hash->appKeyHash}\n";

    $keys = $client->tools()->encryptQiniuKeys('AKxxxxxxxx', 'SKyyyyyyyy');
    echo "ak cipher: {$keys->accessKeyCipher}\n";

    $plainKeys = $client->tools()->decryptQiniuKeys($keys->accessKeyCipher, $keys->secretKeyCipher);
    echo "ak plain: {$plainKeys->accessKey}\n";
} catch (ApiException $e) {
    fprintf(STDERR, "接口错误 code=%d http=%d msg=%s request_id=%s%s",
        $e->apiCode, $e->httpStatus, $e->getMessage(), $e->requestId ?? '-', PHP_EOL);
    exit(1);
} catch (TransportException $e) {
    fprintf(STDERR, "网络错误: %s%s", $e->getMessage(), PHP_EOL);
    exit(1);
}
