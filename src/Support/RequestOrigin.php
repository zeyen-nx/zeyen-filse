<?php


declare(strict_types=1);

namespace Zeyen\Filse\Support;

/**
 * 从「当前这次 HTTP 请求」推导来源 Origin，用于服务端的域名白名单校验。
 * 优先级：Origin 头 → Referer 头 → 转发头/HTTPS 标记 + Host。
 * 只在 http/https 下返回，末尾不带斜杠（符合 Origin 规范）。
 */
final class RequestOrigin
{
    /**
     * @param array<string, mixed>|null $server 默认取 $_SERVER，便于单测注入
     */
    public static function detect(?array $server = null): ?string
    {
        $server ??= $_SERVER;

        $origin = self::sanitize($server['HTTP_ORIGIN'] ?? null);
        if ($origin !== null) {
            return $origin;
        }

        $referer = $server['HTTP_REFERER'] ?? null;
        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origin = self::build($parts['scheme'], $parts['host'], $parts['port'] ?? null);
                if ($origin !== null) {
                    return $origin;
                }
            }
        }

        $host = self::firstValue($server['HTTP_X_FORWARDED_HOST'] ?? $server['HTTP_HOST'] ?? null);
        if ($host === null) {
            return null;
        }

        $proto = self::firstValue($server['HTTP_X_FORWARDED_PROTO'] ?? null)
            ?? (self::isHttps($server) ? 'https' : 'http');

        $parts = parse_url('http://' . $host);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return self::build($proto, $parts['host'], $parts['port'] ?? null);
    }

    private static function sanitize(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        // 跨域被拒时浏览器会发字面量 "null"
        if (strtolower($value) === 'null') {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return self::build($parts['scheme'], $parts['host'], $parts['port'] ?? null);
    }

    private static function build(string $scheme, string $host, ?int $port): ?string
    {
        $scheme = strtolower($scheme);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port !== null && $port !== $defaultPort) {
            return sprintf('%s://%s:%d', $scheme, $host, $port);
        }

        return sprintf('%s://%s', $scheme, $host);
    }

    private static function firstValue(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $first = trim(explode(',', $value)[0]);

        return $first === '' ? null : $first;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return (string)($server['SERVER_PORT'] ?? '') === '443';
    }
}
