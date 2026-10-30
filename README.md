# zeyen/filse

PHP 8.3+ 文件上传客户端（Composer 包）。一行配置接入上传、base64 上传、私有链接、文件列表、删除与密钥工具，不依赖任何框架，原生 / Laravel / ThinkPHP / Hyperf 均可用。

- 零运行时依赖：只用 `ext-curl` + `ext-json`
- 大文件流式上传，内存占用恒定
- 小文件支持 base64 直传（JSON 提交，可带 data URI 前缀）
- 文件后缀以文件头魔数为准，客户端文件名错了也能自动纠正
- 参数按需发送：没设置 `path` / `file_name` 就完全不传
- 所有返回都是只读 DTO，`->fileKey`、`->url` 直接取
- 可选接入框架自带 PSR-18 客户端

---

## 目录

- [环境要求](#环境要求)
- [安装](#安装)
- [快速开始](#快速开始)
- [配置](#配置)
- [API 一览](#api-一览)
- [上传文件](#上传文件)
- [base64 上传](#base64-上传)
- [私有文件链接](#私有文件链接)
- [文件列表](#文件列表)
- [删除文件](#删除文件)
- [运维工具](#运维工具)
- [异常与错误码](#异常与错误码)
- [自定义传输层](#自定义传输层)
- [框架接入](#框架接入)
- [目录结构](#目录结构)
- [常见问题](#常见问题)

---

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | `^8.3` |
| 扩展 | `ext-curl`、`ext-json` |
| Composer | 2.x |

> `ext-fileinfo` 不是必需的，装上后会作为文件类型识别的兜底手段（更准）。

## 安装

发布到 Packagist 后：

```bash
composer require zeyen/filse
```

还没发布时，用 VCS 或本地 path 引入：

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:zeyen-nx/zeyen-filse.git" }
  ],
  "require": {
    "zeyen/filse": "^1.0"
  }
}
```

```json
{
  "repositories": [
    { "type": "path", "url": "../zeyen-filse" }
  ],
  "require": {
    "zeyen/filse": "*"
  }
}
```

## 快速开始

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Zeyen\Filse\Client;

$client = Client::fromEnvFile(__DIR__ . '/.env');

$result = $client->upload(__DIR__ . '/photo.jpg')
    ->path('album/2026')
    ->send();

echo $result->fileKey;   // uploads/demo/2026/10/30/xxx.jpg
echo $result->url;       // https://cdn.example.com/uploads/...
```

## 配置

三种创建方式，任选其一：

```php
use Zeyen\Filse\Client;

// 1) 显式数组
$client = Client::create([
    'base_url' => 'https://api.example.com',
    'app_id'   => 'demo_public',
    'app_key'  => 'demo_key_123456',
]);

// 2) 系统环境变量（Laravel / Symfony / Docker 推荐）
$client = Client::fromEnv();

// 3) 读取 .env 文件（环境变量优先，不会被文件覆盖）
$client = Client::fromEnvFile(__DIR__ . '/.env');
```

多应用切换（同一进程内不同应用上传到各自空间）：

```php
$other = $client->withApp('other_app_id', 'other_app_key');
```

### 配置项

数组键支持 snake_case 与 camelCase。

| 键 | 类型 | 默认 | 说明 |
| --- | --- | --- | --- |
| `base_url` | string | 必填 | 网关地址，不带结尾斜杠；省略协议时自动补 `https://` |
| `app_id` | string | null | 应用 ID |
| `app_key` | string | null | 应用密钥（仅服务端保存，切勿下发前端） |
| `origin` | string | null | 仅用于 CLI / 队列等没有 HTTP 上下文的场景；Web 请求下会自动识别，无需填写 |
| `origin_auto` | bool | true | 是否自动从当前请求推导来源域名 |
| `admin_token` | string | null | 运维令牌，配置后优先于应用凭据 |
| `timeout` | int | 300 | 总超时（秒），大文件建议 ≥ 300 |
| `connect_timeout` | int | 10 | 连接超时（秒） |
| `verify_ssl` | bool | true | 是否校验 TLS 证书 |
| `user_agent` | string | `zeyen-filse/1.0 (Tornado)` | 请求 UA |

### 环境变量

| 变量 | 对应配置 |
| --- | --- |
| `FILSE_BASE_URL` | `base_url` |
| `FILSE_APP_ID` | `app_id` |
| `FILSE_APP_KEY` | `app_key` |
| `FILSE_ORIGIN` | `origin` |
| `FILSE_ORIGIN_AUTO` | `origin_auto` |
| `FILSE_ADMIN_TOKEN` | `admin_token` |
| `FILSE_TIMEOUT` | `timeout` |
| `FILSE_CONNECT_TIMEOUT` | `connect_timeout` |
| `FILSE_VERIFY_SSL` | `verify_ssl` |
| `FILSE_USER_AGENT` | `user_agent` |

优先级：`getenv()` > `$_ENV` > `$_SERVER` > `.env` 文件 > 默认值。

### 关于 Origin

请求会带上来源域名，用于网关的域名白名单校验。取值顺序：`Origin` → `Referer` → `X-Forwarded-Proto`/`HTTPS` + `Host`。

- Web 请求（PHP-FPM / Swoole / RoadRunner）下**无需任何配置**，SDK 自动读取。
- CLI、队列任务没有 HTTP 上下文，需要显式固定：

```php
use Zeyen\Filse\Client;
use Zeyen\Filse\Config;

$client = new Client(
    (new Config('https://api.example.com', 'demo_public', 'demo_key_123456'))
        ->withOrigin('https://example.com')
);
```

> 不要为了绕过校验随便填一个域名，网关会记录来源 IP 与请求 ID。

## API 一览

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `health()` | `array` | 连通性检查 |
| `upload(string $path)` | `UploadRequest` | 本地文件上传（默认流式） |
| `uploadContents(string $bin, ?string $name)` | `UploadRequest` | 二进制内容上传（默认流式） |
| `uploadBase64(string $b64, ?string $name, ?string $path, ?string $mime)` | `UploadResult` | base64 小文件上传 |
| `uploadBase64File(string $path, ?string $name)` | `UploadResult` | 本地小文件转 base64 上传 |
| `privateUrl(string $fileKey, ?int $expires)` | `PrivateUrlResult` | 私有文件换签名链接 |
| `files(int $page, int $size, ?int $accessType)` | `PageResult` | 文件列表 |
| `deleteFile(string $fileKey, bool $dryRun)` | `DeleteResult` | 删对象 + 删记录 |
| `deleteFiles(array $keys, bool $dryRun)` | `array{results,errors}` | 批量删除 |
| `tools()` | `Tools` | 密钥哈希 / 七牛密钥加解密 |
| `withApp(string $id, string $key)` | `Client` | 换应用凭据 |
| `config()` | `Config` | 取当前配置 |

## 上传文件

### 基础用法

```php
$result = $client->upload('/path/to/photo.jpg')->send();

$result->fileKey;     // 对象 key
$result->url;         // 访问地址
$result->fileSize;    // 字节
$result->durationMs;  // 服务端耗时
$result->accessType;  // 1 公有 / 2 私有
$result->isPrivate(); // accessType === 2
```

从内存内容上传（例如刚生成的图片、临时字符串）：

```php
$result = $client->uploadContents($binary, 'avatar.png')->send();
```

### 链式参数

| 方法 | 说明 |
| --- | --- |
| `fileName(string $name)` | 指定文件名。后缀缺失或与真实格式不符时会被纠正 |
| `path(string $path)` | 自定义子路径，如 `album/2026`；不调用则不传 |
| `mimeType(string $mime)` | 强制 MIME；不调用则由文件头推断 |
| `asForm(bool $form = true)` | 走 multipart 通道（≤ 2MB，会读入内存） |
| `asRaw()` | 走原始流通道（默认，大文件用这个） |
| `fileField(string $field)` | 表单通道的文件字段名，默认 `file` |
| `preserveFileName(bool $preserve = true)` | 完全保留传入的文件名，不做后缀纠正 |
| `size(): int` | 待上传字节数 |
| `send(): UploadResult` | 发起请求 |

**参数按需发送**：

```php
$client->upload('/tmp/a.jpg')->send();                       // 不发 file_name / path
$client->upload('/tmp/a.jpg')->path('album')->send();        // 只发 path
$client->upload('/tmp/a.jpg')->fileName('封面.jpg')->send();  // 只发 file_name
```

### 通道选择

| 通道 | 触发方式 | 特点 |
| --- | --- | --- |
| 原始流（默认） | 什么都不用写 | `CURLOPT_UPLOAD` 流式读取，内存占用恒定，适合大文件 |
| multipart | `->asForm()` | 服务端解析表单，可附带普通字段，文件会被整体读入内存 |
| base64 | `uploadBase64()` | JSON 提交，全量进内存，仅适合小文件 |

### 后缀纠正规则

SDK 读取前 4KB 魔数判断真实格式，再决定文件名后缀：

| 传入文件名 | 实际内容 | 最终文件名 |
| --- | --- | --- |
| `55ae...mpbtc5` | JPEG | `55ae...jpg` |
| `photo` | PNG | `photo.png` |
| `photo.jpeg` | JPEG | `photo.jpeg`（等价后缀不改） |
| `a.mpbtc5` | PDF | `a.pdf` |

文本类文件（无魔数）不会被改名，避免把 `.js` / `.csv` 改成 `.txt`。需要原样保留时加 `->preserveFileName()`。

### UploadResult

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `fileId` | string | 文件记录 ID |
| `fileKey` | string | 对象 key |
| `url` | string | 访问地址（私有文件为临时签名链接） |
| `durationMs` | int | 服务端处理耗时 |
| `accessType` | int | 1 公有 / 2 私有 |
| `fileSize` | int | 字节数 |
| `expiresIn` | ?int | 私有文件的链接有效期（秒） |
| `raw` | array | 原始响应 |

## base64 上传

手上已经是一段 base64 字符串时（Canvas 导出、第三方回调、只允许 JSON 的中间层），用这个通道。

```php
use Zeyen\Filse\Exception\ApiException;

// 1) 纯 base64
$res = $client->uploadBase64(
    base64_encode($binary),
    'avatar.png',
    path: 'users/1',
);

// 2) 带 data URI 前缀（原样传即可，服务端会解析）
$res = $client->uploadBase64('data:image/png;base64,iVBORw0KGgo...');

// 3) 不传文件名 → 服务端按文件头自动命名
$res = $client->uploadBase64(base64_encode($png));

// 4) 本地小文件（SDK 帮你编码）
$res = $client->uploadBase64File('/tmp/avatar.png');

echo $res->fileKey;
```

### 方法签名

```php
public function uploadBase64(
    string  $contents,
    ?string $fileName = null,
    ?string $path = null,
    ?string $mimeType = null,
): UploadResult;

public function uploadBase64File(string $localPath, ?string $fileName = null): UploadResult;
```

| 参数 | 说明 |
| --- | --- |
| `$contents` | **必须是 base64 字符串**，支持 URL-safe 变体与 data URI 前缀 |
| `$fileName` | 为空则由服务端按文件头命名 |
| `$path` | 自定义子路径，为空则不传 |
| `$mimeType` | 强制 MIME，传了就不做内容嗅探覆盖 |

### 行为说明

| 情况 | 结果 |
| --- | --- |
| 换行、空格、`-` `_` 变体 | SDK 与网关都能容错解码 |
| 传入的是原始二进制 | 立即抛 `ConfigException`，提示改用 `uploadContents()` |
| data URI 缺逗号 / 非 base64 | 立即抛 `ConfigException` |
| 解码后超过上限 | 网关返回 `41300`（`$e->isPayloadTooLarge()`） |
| 解码后 0 字节 | 网关返回 `40000` |
| 私有应用 | 返回 `url_expires_in`，`url` 为临时签名链接 |

> 上限由服务端 `BASE64_MAX_SIZE` 控制（默认 2MB，指解码后大小）。base64 会让报文膨胀约 33%，且整段进内存，**大文件请继续用 `upload()`**。

## 私有文件链接

私有应用上传的文件不能直接访问，需要用 `file_key` 换签名链接：

```php
$link = $client->privateUrl($result->fileKey, 600);
echo $link->url;        // 带 e=...&token=...
echo $link->expiresIn;  // 600
```

`expires` 省略时使用服务端默认值，服务端会对上下限做收敛。

## 文件列表

```php
$page = $client->files(page: 1, pageSize: 20, accessType: null);

$page->total;
$page->hasMore();

foreach ($page->items as $file) {
    printf("%s %s %d bytes %s\n",
        $file->fileKey, $file->mimeType, $file->fileSize, $file->createdAt ?? '-');
}
```

`FileInfo` 字段：`fileId`、`appId`、`accessType`、`fileKey`、`fileName`、`fileExt`、`mimeType`、`fileSize`、`etag`、`uploadMode`（1 直传 / 2 分片）、`chunkCount`、`durationMs`、`url`、`status`、`createdAt`、`raw`。

## 删除文件

按 `file_key` 删除对象和对应记录，幂等：

```php
use Zeyen\Filse\Exception\ApiException;

// 1) 试运行，只回显信息，不真删
$preview = $client->deleteFile('uploads/demo/2026/10/30/xxx.jpg', dryRun: true);
printf("%d 字节，创建于 %s\n", $preview->fileSize, $preview->createdAt);

// 2) 真删
$res = $client->deleteFile('uploads/demo/2026/10/30/xxx.jpg');
if ($res->alreadyAbsent) {
    echo "对象已不存在，只清理了记录\n";
}
echo "已删除，清理记录 {$res->records} 条\n";

// 3) 也接受完整 URL
$client->deleteFile('https://cdn.example.com/uploads/demo/2026/10/30/xxx.jpg');

// 4) 批量：逐条执行，单条失败不影响其余
$out = $client->deleteFiles([
    'uploads/demo/2026/10/30/a.jpg',
    'uploads/demo/2026/10/30/b.png',
]);

foreach ($out['results'] as $key => $r) {
    echo "OK  {$key} records={$r->records}\n";
}
foreach ($out['errors'] as $key => $msg) {
    echo "ERR {$key}: {$msg}\n";
}
```

### DeleteResult

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `fileKey` | string | 规范化后的 key（去掉开头 `/`、从 URL 截取） |
| `dryRun` | bool | 是否试运行 |
| `deleted` | bool | 是否已删除 |
| `alreadyAbsent` | bool | 对象本来就不存在（记录已清理） |
| `records` | int | 清理的记录条数 |
| `fileSize` / `accessType` / `createdAt` | — | 仅试运行时返回 |
| `raw` | array | 原始响应 |

## 运维工具

```php
$tools = $client->tools();

// 明文密钥 -> SHA-256 哈希（无需应用凭据，可提前在前端/CI 计算）
$hash = $tools->appKeyHash('demo_key_123456');
echo $hash->appKeyHash;      // 64 位 hex
echo $hash->appKeyPrefix;    // 前 6 位，便于核对
echo $hash->appKeyLength;

// 七牛 AK/SK 明文 -> 密文（需要应用凭据或 admin_token）
$keys = $tools->encryptQiniuKeys('AKxxxxxxxx', 'SKyyyyyyyy');
echo $keys->accessKeyCipher;   // gAAAAA...
echo $keys->alreadyEncrypted;  // true 表示传入的本身就是密文

// 密文 -> 明文
$plain = $tools->decryptQiniuKeys($keys->accessKeyCipher, $keys->secretKeyCipher);
echo $plain->accessKey;
```

两个参数都支持只传一个（只加密/解密 AK 或只处理 SK）。

`QiniuKeyResult` 字段：`accessKey`、`secretKey`（解密结果）、`accessKeyCipher`、`secretKeyCipher`（加密结果）、`accessKeyHint`、`secretKeyHint`（脱敏提示）、`alreadyEncrypted`、`raw`。

## 异常与错误码

```php
use Zeyen\Filse\Exception\ApiException;
use Zeyen\Filse\Exception\ConfigException;
use Zeyen\Filse\Exception\TransportException;

try {
    $client->upload('/path/to/a.jpg')->send();
} catch (ApiException $e) {          // 网关返回 code != 0
    $e->apiCode;     // 40000
    $e->httpStatus;  // 400
    $e->requestId;   // 便于对日志
    $e->payload;     // 完整响应体
    $e->isAuthError();
    $e->isQuotaExceeded();
    $e->isPayloadTooLarge();
} catch (TransportException $e) {    // 网络层失败（DNS、超时、TLS）
    $e->getMessage();
} catch (ConfigException $e) {       // 配置缺失（base_url、凭据、非法入参）
    $e->getMessage();
}
```

| code | 含义 | 建议处理 |
| --- | --- | --- |
| `0` | 成功 | — |
| `40000` | 参数错误 | 检查 `file_key`、`path`、`expires`、base64 内容 |
| `40100` | 应用不存在或密钥错误 | 核对 `app_id` / `app_key` |
| `40300` | 来源域名不在白名单 / 权限不足 | 看异常信息里的 Origin，把该域名加入白名单 |
| `40400` | 文件不存在 | 删除、私有链接时先确认 key |
| `41300` | 文件或请求体过大 | 检查应用上限与 base64 通道上限 |
| `42900` | 配额超限 | 次日重试或提升配额 |
| `50000` | 服务端内部错误 | 带 `request_id` 反馈 |
| `50200` | 上游存储错误 | 可重试；持续失败联系运维 |
| `-1` | 响应不是合法 JSON | 多半是打到了网关以外的地址 |

除 `ApiException` 外，所有异常都继承自 `Zeyen\Filse\Exception\FilseException`，可以只捕获它做兜底。

## 自定义传输层

默认 `CurlTransport`（流式、零依赖）。需要接入框架自带客户端时用 `Psr18Transport`：

```bash
composer require psr/http-client psr/http-factory psr/http-message
```

```php
use Zeyen\Filse\Client;
use Zeyen\Filse\Http\Psr18Transport;

$transport = new Psr18Transport($psr18Client, $requestFactory, $streamFactory);

$client = Client::fromEnv(transport: $transport);
// 或 new Client(Config::fromEnv(), $transport);
```

> PSR-18 不支持流式请求体，文件会被整体读入内存；大文件请用默认实现。超时需要在 PSR-18 客户端上自行配置。

也可以自行实现 `Zeyen\Filse\Http\TransportInterface`（只有一个 `send(HttpRequest): HttpResponse`）。

## 框架接入

### 原生 / 任意框架

```php
require __DIR__ . '/vendor/autoload.php';

$client = Zeyen\Filse\Client::fromEnv();
```

### Laravel

`config/services.php`：

```php
'filse' => [
    'base_url' => env('FILSE_BASE_URL'),
    'app_id'   => env('FILSE_APP_ID'),
    'app_key'  => env('FILSE_APP_KEY'),
],
```

`AppServiceProvider::register()` 注册单例：

```php
use Zeyen\Filse\Client;

$this->app->singleton(Client::class, fn () => Client::fromEnv());
```

控制器里注入即可：

```php
public function store(Request $request, Client $client)
{
    $file = $request->file('image');

    return $client->upload($file->getRealPath())
        ->fileName($file->getClientOriginalName())
        ->path('users/' . $request->user()->id)
        ->send();
}
```

前端直接丢 base64 过来的场景：

```php
public function avatar(Request $request, Client $client)
{
    return $client->uploadBase64(
        (string) $request->input('image'),
        'avatar.png',
        path: 'users/' . $request->user()->id,
    );
}
```

### ThinkPHP

```php
use Zeyen\Filse\Client;

class Upload extends BaseController
{
    public function index()
    {
        $client = Client::fromEnv();
        $file = request()->file('image');

        return json($client->upload($file->getPathname())
            ->fileName($file->getOriginalName())
            ->send());
    }
}
```

### Hyperf / Swoole

注入 PSR-18 客户端即可，注意协程环境下不要跨协程复用同一个 `Client` 实例。

## 目录结构

```
zeyen-filse/
├── composer.json
├── .env.example
├── examples/
│   └── plain.php
└── src/
    ├── Client.php              # 入口：上传/base64/私有链接/列表/删除/工具
    ├── Config.php              # 配置解析（数组 / 环境变量 / .env）
    ├── Tools.php               # 运维工具接口
    ├── Exception/
    │   ├── ApiException.php
    │   ├── ConfigException.php
    │   ├── FilseException.php
    │   └── TransportException.php
    ├── Http/
    │   ├── CurlTransport.php   # 默认传输层（流式上传）
    │   ├── HttpRequest.php
    │   ├── HttpResponse.php
    │   ├── Psr18Transport.php  # 可选：接入框架 HTTP 客户端
    │   └── TransportInterface.php
    ├── Support/
    │   ├── Env.php             # 环境变量读取
    │   ├── MimeSniffer.php     # 文件头嗅探 + 后缀纠正
    │   ├── MimeTypes.php       # 扩展名 / MIME 映射
    │   └── RequestOrigin.php   # 来源域名识别
    └── Upload/
        ├── AppKeyHashResult.php
        ├── DeleteResult.php
        ├── FileInfo.php
        ├── PageResult.php
        ├── PrivateUrlResult.php
        ├── QiniuKeyResult.php
        ├── UploadRequest.php
        └── UploadResult.php
```

## 常见问题

**Q：报 `40300 域名不在白名单内`**
看异常信息里带的 Origin。Web 请求下 SDK 会自动取当前请求域名；若是 CLI/队列，用 `Config::withOrigin('https://你的域名')` 固定。也可以让服务端把该域名加进应用白名单。

**Q：上传成功但 `file_key` 后缀不对**
SDK 已按文件头纠正后缀。若确实需要原样保留（例如特殊后缀的业务文件），调用 `->preserveFileName()`，并自行承担后果。

**Q：报 `40000 缺少 file 字段`**
表单通道里 `filename` 为空会被服务端当成普通字段。SDK 保证了 part filename 一定非空，出现该错误通常是自己手写了 multipart 请求。

**Q：base64 上传报 `ConfigException`**
`uploadBase64()` 的第一个参数必须是 base64 **字符串**。手上是二进制或文件路径时，用 `uploadContents()` / `upload()`，或 `uploadBase64File()`。

**Q：base64 上传报 `41300`**
超过服务端 `BASE64_MAX_SIZE`（默认 2MB，解码后大小）。大文件请走原始流通道。

**Q：大文件上传内存爆掉**
确认没有调用 `->asForm()` 或 `uploadBase64*()`。默认原始流通道是流式读取，内存占用恒定。

**Q：上传很慢，每次都多等 1 秒**
cURL 默认会发 `Expect: 100-continue`。`CurlTransport` 已显式禁用，自定义传输层时记得加 `Expect:` 空头。

**Q：PHP 报 `upload_max_filesize` 限制**
SDK 走的是 cURL 直连，不受 `upload_max_filesize` 约束；只有表单通道经过 PHP 解析时才受 `post_max_size` 影响。

**Q：`app_key` 能放前端吗**
不能。前端只应拿到后端签发的临时凭据，或由后端代为上传。

**Q：删除后配额会退还吗**
不会。配额统计的是累计用量，不是当前存储量。

## License

MIT
