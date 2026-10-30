<?php

declare(strict_types=1);

namespace Zeyen\Filse;

use Zeyen\Filse\Exception\ConfigException;
use Zeyen\Filse\Support\Env;

final class Config
{
    public readonly string $baseUrl;
    public readonly ?string $appId;
    public readonly ?string $appKey;

    /** 显式指定的 Origin；为 null 时按当前请求自动推导 */
    public readonly ?string $origin;

    /** 未显式指定 origin 时是否自动从当前请求推导（默认 true） */
    public readonly bool $originAuto;

    public readonly ?string $adminToken;
    public readonly int $timeout;
    public readonly int $connectTimeout;
    public readonly bool $verifySsl;
    public readonly string $userAgent;

    public function __construct(
        string $baseUrl,
        ?string $appId = null,
        ?string $appKey = null,
        ?string $origin = null,
        ?string $adminToken = null,
        int $timeout = 300,
        int $connectTimeout = 10,
        bool $verifySsl = true,
        ?string $userAgent = null,
        bool $originAuto = true,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            throw new ConfigException('base_url 不能为空，例如 https://api.example.com');
        }
        if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://' . $baseUrl;
        }

        $this->baseUrl = $baseUrl;
        $this->appId = self::str($appId);
        $this->appKey = self::str($appKey);
        $this->origin = self::str($origin);
        $this->originAuto = $originAuto;
        $this->adminToken = self::str($adminToken);
        $this->timeout = max(1, $timeout);
        $this->connectTimeout = max(1, $connectTimeout);
        $this->verifySsl = $verifySsl;
        $this->userAgent = self::str($userAgent) ?? 'zeyen-filse/1.0 (Tornado)';
    }

    /**
     * 支持 snake_case 与 camelCase 两种键名。
     */
    public static function fromArray(array $config): self
    {
        return new self(
            baseUrl: (string) ($config['base_url'] ?? $config['baseUrl'] ?? ''),
            appId: self::pick($config, 'app_id', 'appId'),
            appKey: self::pick($config, 'app_key', 'appKey'),
            origin: self::pick($config, 'origin'),
            adminToken: self::pick($config, 'admin_token', 'adminToken'),
            timeout: self::toInt($config['timeout'] ?? null, 300),
            connectTimeout: self::toInt($config['connect_timeout'] ?? $config['connectTimeout'] ?? null, 10),
            verifySsl: self::toBool($config['verify_ssl'] ?? $config['verifySsl'] ?? null, true),
            userAgent: self::pick($config, 'user_agent', 'userAgent'),
            originAuto: self::toBool($config['origin_auto'] ?? $config['originAuto'] ?? null, true),
        );
    }

    /**
     * 从环境变量创建，默认前缀 FILSE_。
     *
     * @param array $overrides 优先级高于环境变量
     */
    public static function fromEnv(string $prefix = 'FILSE_', array $overrides = []): self
    {
        $config = array_filter([
            'base_url' => Env::get($prefix . 'BASE_URL'),
            'app_id' => Env::get($prefix . 'APP_ID'),
            'app_key' => Env::get($prefix . 'APP_KEY'),
            'origin' => Env::get($prefix . 'ORIGIN'),
            'origin_auto' => Env::get($prefix . 'ORIGIN_AUTO'),
            'admin_token' => Env::get($prefix . 'ADMIN_TOKEN'),
            'timeout' => Env::get($prefix . 'TIMEOUT'),
            'connect_timeout' => Env::get($prefix . 'CONNECT_TIMEOUT'),
            'verify_ssl' => Env::get($prefix . 'VERIFY_SSL'),
            'user_agent' => Env::get($prefix . 'USER_AGENT'),
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return self::fromArray(array_merge($config, $overrides));
    }

    /**
     * 先读取 .env 文件（不覆盖已存在的真实环境变量），再从环境变量创建。
     */
    public static function fromEnvFile(string $path, string $prefix = 'FILSE_', array $overrides = []): self
    {
        Env::loadFile($path);

        return self::fromEnv($prefix, $overrides);
    }

    public function hasAppCredentials(): bool
    {
        return $this->appId !== null && $this->appKey !== null;
    }

    /**
     * 派生一个新配置，替换应用凭据（多应用场景）。
     */
    public function withApp(string $appId, string $appKey): self
    {
        return new self(
            baseUrl: $this->baseUrl,
            appId: $appId,
            appKey: $appKey,
            origin: $this->origin,
            adminToken: $this->adminToken,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            verifySsl: $this->verifySsl,
            userAgent: $this->userAgent,
            originAuto: $this->originAuto,
        );
    }

    /**
     * 派生一个新配置，固定 Origin（队列任务、CLI 场景使用）。
     */
    public function withOrigin(?string $origin): self
    {
        return new self(
            baseUrl: $this->baseUrl,
            appId: $this->appId,
            appKey: $this->appKey,
            origin: $origin,
            adminToken: $this->adminToken,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            verifySsl: $this->verifySsl,
            userAgent: $this->userAgent,
            originAuto: false,
        );
    }

    private static function str(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }

    private static function pick(array $config, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($config[$key])) {
                return self::str((string) $config[$key]);
            }
        }

        return null;
    }

    private static function toInt(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private static function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
