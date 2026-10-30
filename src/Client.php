<?php


declare(strict_types=1);

namespace Zeyen\Filse;

use Zeyen\Filse\Exception\ApiException;
use Zeyen\Filse\Exception\ConfigException;
use Zeyen\Filse\Exception\TransportException;
use Zeyen\Filse\Http\CurlTransport;
use Zeyen\Filse\Http\HttpRequest;
use Zeyen\Filse\Http\HttpResponse;
use Zeyen\Filse\Http\TransportInterface;
use Zeyen\Filse\Upload\PageResult;
use Zeyen\Filse\Upload\PrivateUrlResult;
use Zeyen\Filse\Upload\UploadRequest;
use Zeyen\Filse\Upload\UploadResult;
use Zeyen\Filse\Support\RequestOrigin;

final class Client
{
    private readonly TransportInterface $transport;

    public function __construct(
        private readonly Config $config,
        ?TransportInterface     $transport = null,
    )
    {
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * @param array<string, mixed> $config 见 Config::fromArray
     */
    public static function create(array $config, ?TransportInterface $transport = null): self
    {
        return new self(Config::fromArray($config), $transport);
    }

    public static function fromEnv(string $prefix = 'FILSE_', array $overrides = [], ?TransportInterface $transport = null): self
    {
        return new self(Config::fromEnv($prefix, $overrides), $transport);
    }

    public static function fromEnvFile(string $path, string $prefix = 'FILSE_', array $overrides = [], ?TransportInterface $transport = null): self
    {
        return new self(Config::fromEnvFile($path, $prefix, $overrides), $transport);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function withApp(string $appId, string $appKey): self
    {
        return new self($this->config->withApp($appId, $appKey), $this->transport);
    }

    public function tools(): Tools
    {
        return new Tools($this);
    }

    // ---------------- 业务接口 ----------------

    /**
     * 数据库连通性检查。
     * @return array{status?: string}
     */
    public function health(): array
    {
        return $this->decode($this->transport->send(
            new HttpRequest(
                method: 'GET',
                url: $this->url('/health'),
                headers: $this->baseHeaders(false),
                timeout: (float)$this->config->timeout,
                connectTimeout: (float)$this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        ));
    }

    public function upload(string $localPath): UploadRequest
    {
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new ConfigException('文件不存在或不可读: ' . $localPath);
        }

        return new UploadRequest($this, $localPath);
    }

    public function uploadContents(string $contents, ?string $fileName = null): UploadRequest
    {
        return (new UploadRequest($this, null, $contents))->fileName($fileName);
    }

    public function privateUrl(string $fileKey, ?int $expires = null): PrivateUrlResult
    {
        $payload = ['file_key' => $fileKey];
        if ($expires !== null) {
            $payload['expires'] = $expires;
        }

        $headers = $this->baseHeaders(true);
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $body = http_build_query($payload);

        return PrivateUrlResult::fromArray($this->decode($this->transport->send(
            new HttpRequest(
                method: 'POST',
                url: $this->url('/api/v1/private-url'),
                headers: $headers,
                body: $body,
                contentLength: strlen($body),
                timeout: (float)$this->config->timeout,
                connectTimeout: (float)$this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        )));
    }

    public function files(int $page = 1, int $pageSize = 20, ?int $accessType = null): PageResult
    {
        $query = ['page' => max(1, $page), 'page_size' => max(1, min(100, $pageSize))];
        if ($accessType === 1 || $accessType === 2) {
            $query['access_type'] = $accessType;
        }

        return PageResult::fromArray($this->decode($this->transport->send(
            new HttpRequest(
                method: 'GET',
                url: $this->url('/api/v1/files', $query),
                headers: $this->baseHeaders(true),
                timeout: (float)$this->config->timeout,
                connectTimeout: (float)$this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        )));
    }

    /**
     * 按 file_key 删除文件：删七牛对象 + 删除上传记录。
     *
     * 幂等：七牛侧对象已不存在时服务端仍返回成功（alreadyAbsent=true）。
     * 记录不存在抛 ApiException(code=40400)。
     * 也接受完整访问 URL，会自动截成 key。
     *
     * @param bool $dryRun true 时只校验（回显 file_size / access_type / created_at），不真删
     */
    public function deleteFile(string $fileKey, bool $dryRun = false): DeleteResult
    {
        $fileKey = $this->normalizeFileKey($fileKey);
        if ($fileKey === '') {
            throw new ConfigException('file_key 不能为空');
        }

        $payload = ['file_key' => $fileKey];
        if ($dryRun) {
            $payload['dry_run'] = '1';
        }

        $headers = $this->baseHeaders(true);
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $body = http_build_query($payload);

        return DeleteResult::fromArray($this->decode($this->transport->send(
            new HttpRequest(
                method: 'POST',
                url: $this->url('/api/v1/files/delete'),
                headers: $headers,
                body: $body,
                contentLength: strlen($body),
                timeout: (float)$this->config->timeout,
                connectTimeout: (float)$this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        )));
    }

    /**
     * 批量删除：逐条调用，单条失败不影响其余。
     *
     * @param list<string> $fileKeys
     * @return array{results: array<string, DeleteResult>, errors: array<string, string>}
     */
    public function deleteFiles(array $fileKeys, bool $dryRun = false): array
    {
        $results = [];
        $errors = [];

        foreach ($fileKeys as $fileKey) {
            $fileKey = $this->normalizeFileKey((string)$fileKey);
            if ($fileKey === '' || isset($results[$fileKey]) || isset($errors[$fileKey])) {
                continue;
            }
            try {
                $results[$fileKey] = $this->deleteFile($fileKey, $dryRun);
            } catch (ApiException $e) {
                $errors[$fileKey] = $e->getMessage();
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }


    /**
     * 允许传完整 URL，自动截出 key（服务端不接受以 / 开头的 key）。
     */
    private function normalizeFileKey(string $fileKey): string
    {
        $fileKey = trim($fileKey);
        if ($fileKey === '') {
            return '';
        }

        if (str_starts_with($fileKey, 'http://') || str_starts_with($fileKey, 'https://')) {
            $path = (string)parse_url($fileKey, PHP_URL_PATH);
            $fileKey = rawurldecode($path);
        }

        return ltrim($fileKey, '/');
    }

    /**
    * 本地预校验，避免把非法内容发给服务端后才报 40000。
    */
    private function assertBase64(string $contents): void
    {
        if ($contents === '') {
            throw new ConfigException('base64 内容不能为空');
        }

        $probe = $contents;
        if (str_starts_with($probe, 'data:')) {
            $comma = strpos($probe, ',');
            if ($comma === false) {
                throw new ConfigException('data URI 缺少逗号分隔符');
            }
            $probe = substr($probe, $comma + 1);
        }

        $probe = preg_replace('/\s+/', '', $probe) ?? '';
        if ($probe === '') {
            throw new ConfigException('base64 内容不能为空');
        }

        if (preg_match('#^[A-Za-z0-9+/=_-]+$#', $probe) !== 1 || base64_decode($probe, true) === false) {
            throw new ConfigException(
                '$contents 必须是 base64 字符串；二进制内容请先 base64_encode()，'
                . '或改用 uploadContents() 走原始流上传'
            );
        }
    }


    // ---------------- 内部：供 UploadRequest / Tools 调用 ----------------

    /**
     * @param array<string, scalar> $query
     * @internal
     */
    public function rawUpload(array $query, ?string $filePath, ?string $contents, ?string $contentType = null): array
    {
        $headers = $this->baseHeaders(true);
        $headers['Content-Type'] = $contentType ?? 'application/octet-stream';

        $size = $filePath !== null ? (int) filesize($filePath) : strlen((string) $contents);
        $headers['Content-Length'] = (string) $size;

        return $this->decode($this->transport->send(
            new HttpRequest(
                method: 'POST',
                url: $this->url('/api/v1/upload', $query),
                headers: $headers,
                body: $filePath === null ? (string) $contents : null,
                filePath: $filePath,
                contentLength: $size,
                timeout: (float) $this->config->timeout,
                connectTimeout: (float) $this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        ));
    }

    /**
     * @internal
     */
    public function multipartUpload(string $body, string $boundary): array
    {
        $headers = $this->baseHeaders(true);
        $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        $headers['Content-Length'] = (string)strlen($body);

        return $this->decode($this->transport->send(
            new HttpRequest(
                method: 'POST',
                url: $this->url('/api/v1/upload'),
                headers: $headers,
                body: $body,
                contentLength: strlen($body),
                timeout: (float)$this->config->timeout,
                connectTimeout: (float)$this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        ));
    }

    /**
     * base64 上传（小文件通道，服务端默认上限 2MB，超出会返回 41300）。
     *
     * - $contents 必须是 base64 字符串，可带 data URI 前缀（data:image/png;base64,xxx）
     * - 已经是二进制内容请用 uploadContents()（走原始流，更省内存）
     * - $fileName 为空时由服务端按文件头自动命名
     *
     * @param string|null $path     自定义子路径，如 users/1；为空则不传
     * @param string|null $mimeType 强制 MIME；为空则由服务端按文件头推断
     */
    public function uploadBase64(
        string  $contents,
        ?string $fileName = null,
        ?string $path = null,
        ?string $mimeType = null,
    ): UploadResult
    {
        $contents = trim($contents);
        $this->assertBase64($contents);

        $payload = ['file' => $contents];
        if ($fileName !== null && trim($fileName) !== '') {
            $payload['file_name'] = trim($fileName);
        }
        if ($path !== null && trim($path) !== '') {
            $payload['path'] = trim($path);
        }
        if ($mimeType !== null && trim($mimeType) !== '') {
            $payload['mime_type'] = trim($mimeType);
        }

        return UploadResult::fromArray(
            $this->postJson('/api/v1/upload/base64', $payload, true)
        );
    }


    /**
     * 把本地文件读出来做 base64 上传。
     * 仅适合小文件（base64 会放大 33% 且整段进内存），大文件请用 upload()。
     */
    public function uploadBase64File(string $localPath, ?string $fileName = null): UploadResult
    {
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new ConfigException('文件不存在或不可读: ' . $localPath);
        }

        $contents = file_get_contents($localPath);
        if ($contents === false) {
            throw new ConfigException('读取文件失败: ' . $localPath);
        }

        return $this->uploadBase64(
            base64_encode($contents),
            $fileName ?? basename($localPath)
        );
    }

    /**
     * 通用 JSON POST，返回响应里的 data 部分。
     *
     * @param array<string, mixed> $payload
     */
    public function postJson(string $path, array $payload, bool $appAuth = true): array
    {
        $headers = $this->baseHeaders($appAuth);
        $headers['Content-Type'] = 'application/json';

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = $body === false ? '{}' : $body;

        return $this->decode($this->transport->send(
            new HttpRequest(
                method: 'POST',
                url: $this->url($path),
                headers: $headers,
                body: $body,
                contentLength: strlen($body),
                timeout: (float)$this->config->timeout,
                connectTimeout: (float)$this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        ));
    }


    /**
     * @internal
     */
    public function toolsRequest(string $path, array $payload, bool $appAuth): array
    {
        $headers = $this->baseHeaders($appAuth);
        $headers['Content-Type'] = 'application/json';

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = $body === false ? '{}' : $body;

        return $this->decode($this->transport->send(
            new HttpRequest(
                method: 'POST',
                url: $this->url($path),
                headers: $headers,
                body: $body,
                contentLength: strlen($body),
                timeout: (float)$this->config->timeout,
                connectTimeout: (float)$this->config->connectTimeout,
                verifySsl: $this->config->verifySsl,
            )
        ));
    }

    // ---------------- 内部工具 ----------------

    /**
     * @param array<string, scalar> $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = $this->config->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * @return array<string, string>
     */
    private function baseHeaders(bool $appAuth): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent,
        ];

        // 默认按「当前这次请求」推导：Origin → Referer → Host + 协议
        $origin = $this->config->origin
            ?? ($this->config->originAuto ? RequestOrigin::detect() : null);
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        if ($appAuth && $this->config->hasAppCredentials()) {
            $headers['X-App-Id'] = (string) $this->config->appId;
            $headers['X-App-Key'] = (string) $this->config->appKey;

            return $headers;
        }

        if ($this->config->adminToken !== null) {
            $headers['X-Admin-Token'] = $this->config->adminToken;

            return $headers;
        }

        if ($appAuth) {
            throw new ConfigException('需要配置 app_id 与 app_key（或 admin_token）');
        }

        return $headers;
    }

    private function decode(HttpResponse $response): array
    {
        $json = $response->json();
        if ($json === null) {
            throw new ApiException(
                apiCode: -1,
                message: sprintf('响应不是合法 JSON (HTTP %d): %s', $response->status, mb_substr($response->body, 0, 300)),
                httpStatus: $response->status,
            );
        }

        $code = (int)($json['code'] ?? -1);
        if ($code !== 0) {
            throw new ApiException(
                apiCode: $code,
                message: (string)($json['msg'] ?? '未知错误'),
                httpStatus: $response->status,
                requestId: isset($json['request_id']) ? (string)$json['request_id'] : null,
                payload: $json,
            );
        }

        $data = $json['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
