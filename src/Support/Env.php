<?php


declare(strict_types=1);

namespace Zeyen\Filse\Support;

/**
 * 环境变量读取：真实环境变量 > $_ENV > $_SERVER > .env 文件。
 */
final class Env
{
    /** @var array<string, string> */
    private static array $fileValues = [];

    public static function loadFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("env 文件不可读: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if ($key === '') {
                continue;
            }
            self::$fileValues[$key] = self::normalizeValue(trim(substr($line, $pos + 1)));
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }

        return self::$fileValues[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value !== null && $value !== '' ? (int)$value : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public static function all(): array
    {
        return self::$fileValues;
    }

    public static function reset(): void
    {
        self::$fileValues = [];
    }

    private static function normalizeValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
            $comment = strpos($value, ' #');
            if ($comment !== false) {
                $value = rtrim(substr($value, 0, $comment));
            }
        }

        return str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $value);
    }
}
