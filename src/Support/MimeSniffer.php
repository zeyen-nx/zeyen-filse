<?php


declare(strict_types=1);

namespace Zeyen\Filse\Support;

/**
 * 文件头嗅探：只读前 4KB 魔数，判定真实格式。
 * 只做「二进制魔数」判断，不做文本启发式（避免把 .js/.csv/.md 误判成 .txt）。
 * 识别不出时返回 null，调用方保持原文件名不变。
 */
final class MimeSniffer
{
    private const PROBE_SIZE = 4096;

    /** 同一格式允许的多种后缀，避免把 .jpeg 强改成 .jpg、把 .docx 改成 .zip */
    private const EQUIVALENTS = [
        'jpg' => ['jpg', 'jpeg', 'jpe', 'jfif'],
        'zip' => ['zip', 'docx', 'xlsx', 'pptx', 'epub', 'apk', 'jar', 'whl'],
        'doc' => ['doc', 'xls', 'ppt', 'msi'],
        'mp4' => ['mp4', 'm4v'],
        'tif' => ['tif', 'tiff'],
        'html' => ['html', 'htm'],
    ];

    /** @var list<array{0:string,1:string,2:string}> [magic, ext, mime] */
    private const SIGNATURES = [
        ["\x89PNG\r\n\x1a\n", 'png', 'image/png'],
        ["\xFF\xD8\xFF", 'jpg', 'image/jpeg'],
        ['GIF89a', 'gif', 'image/gif'],
        ['GIF87a', 'gif', 'image/gif'],
        ["Rar!\x1a\x07\x01\x00", 'rar', 'application/x-rar-compressed'],
        ["Rar!\x1a\x07\x00", 'rar', 'application/x-rar-compressed'],
        ["7z\xBC\xAF\x27\x1C", '7z', 'application/x-7z-compressed'],
        ["SQLite format 3\x00", 'sqlite', 'application/x-sqlite3'],
        ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'doc', 'application/msword'],
        ['%PDF-', 'pdf', 'application/pdf'],
        ["\x1F\x8B", 'gz', 'application/gzip'],
        ['BZh', 'bz2', 'application/x-bzip2'],
        ['ID3', 'mp3', 'audio/mpeg'],
        ['OggS', 'ogg', 'audio/ogg'],
        ['fLaC', 'flac', 'audio/flac'],
        ["\x00\x00\x01\x00", 'ico', 'image/vnd.microsoft.icon'],
        ["\x7FELF", 'so', 'application/x-executable'],
        ["PK\x03\x04", 'zip', 'application/zip'],
        ["PK\x05\x06", 'zip', 'application/zip'],
        ['MZ', 'exe', 'application/x-dosexec'],
    ];

    /**
     * @return array{ext:string, mime:string}|null
     */
    public static function detect(?string $filePath, ?string $contents = null): ?array
    {
        $head = self::readHead($filePath, $contents);
        if ($head === '') {
            return null;
        }

        $hit = self::match($head);
        if ($hit !== null) {
            return $hit;
        }

        return self::fromFinfo($filePath, $contents);
    }

    /**
     * 用真实格式纠正文件名的后缀；格式与原后缀等价时保持原样。
     * @param array{ext:string, mime:string}|null $detected
     */
    public static function alignName(string $name, ?array $detected): string
    {
        if ($detected === null) {
            return $name;
        }

        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $accepted = self::EQUIVALENTS[$detected['ext']] ?? [$detected['ext']];
        if ($ext !== '' && in_array($ext, $accepted, true)) {
            return $name;
        }

        $stem = (string)pathinfo($name, PATHINFO_FILENAME);
        if ($stem === '' || $stem === '.') {
            $stem = bin2hex(random_bytes(8));
        }

        return $stem . '.' . $detected['ext'];
    }

    private static function readHead(?string $filePath, ?string $contents): string
    {
        if ($filePath !== null) {
            $fp = @fopen($filePath, 'rb');
            if ($fp === false) {
                return '';
            }
            $head = (string)fread($fp, self::PROBE_SIZE);
            fclose($fp);

            return $head;
        }

        return $contents === null ? '' : substr($contents, 0, self::PROBE_SIZE);
    }

    /**
     * @return array{ext:string, mime:string}|null
     */
    private static function match(string $head): ?array
    {
        // RIFF：WEBP / WAVE / AVI
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF') {
            $tag = substr($head, 8, 4);
            if ($tag === 'WEBP') {
                return ['ext' => 'webp', 'mime' => 'image/webp'];
            }
            if ($tag === 'WAVE') {
                return ['ext' => 'wav', 'mime' => 'audio/wav'];
            }
            if ($tag === 'AVI ') {
                return ['ext' => 'avi', 'mime' => 'video/x-msvideo'];
            }
        }

        // EBML：WebM / MKV
        if (substr($head, 0, 4) === "\x1a\x45\xdf\xa3") {
            return stripos(substr($head, 0, 64), 'webm') !== false
                ? ['ext' => 'webm', 'mime' => 'video/webm']
                : ['ext' => 'mkv', 'mime' => 'video/x-matroska'];
        }

        // ISO-BMFF：mp4 / mov / heic / avif
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if ($brand === 'qt  ') {
                return ['ext' => 'mov', 'mime' => 'video/quicktime'];
            }
            if (in_array($brand, ['heic', 'heix', 'heim', 'heis', 'mif1', 'msf1'], true)) {
                return ['ext' => 'heic', 'mime' => 'image/heic'];
            }
            if (in_array($brand, ['avif', 'avis'], true)) {
                return ['ext' => 'avif', 'mime' => 'image/avif'];
            }

            return ['ext' => 'mp4', 'mime' => 'video/mp4'];
        }

        foreach (self::SIGNATURES as [$magic, $ext, $mime]) {
            if (strncmp($head, $magic, strlen($magic)) === 0) {
                if ($ext === 'zip') {
                    return self::refineZip($head);
                }

                return ['ext' => $ext, 'mime' => $mime];
            }
        }

        // BMP 的 "BM" 太宽松，额外校验保留字节，避免误伤以 BM 开头的文本
        if (strlen($head) >= 14 && substr($head, 0, 2) === 'BM' && substr($head, 6, 4) === "\x00\x00\x00\x00") {
            return ['ext' => 'bmp', 'mime' => 'image/bmp'];
        }

        return null;
    }

    /**
     * @return array{ext:string, mime:string}|null
     */
    private static function refineZip(string $head): array
    {
        $probe = substr($head, 0, 2048);
        $low = strtolower($probe);

        if (str_contains($low, '[content_types].xml')) {
            if (str_contains($low, '/word/')) {
                return ['ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            }
            if (str_contains($low, '/xl/')) {
                return ['ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            }
            if (str_contains($low, '/ppt/')) {
                return ['ext' => 'pptx', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
            }
        }
        if (str_contains($low, 'application/epub')) {
            return ['ext' => 'epub', 'mime' => 'application/epub+zip'];
        }
        if (str_contains($low, 'androidmanifest.xml')) {
            return ['ext' => 'apk', 'mime' => 'application/vnd.android.package-archive'];
        }

        return ['ext' => 'zip', 'mime' => 'application/zip'];
    }

    /**
     * 魔数没命中时，用 fileinfo 兜底（PHP 默认带 fileinfo 扩展）。
     * @return array{ext:string, mime:string}|null
     */
    private static function fromFinfo(?string $filePath, ?string $contents): ?array
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $filePath !== null ? $finfo->file($filePath) : $finfo->buffer((string)$contents);
        if (!is_string($mime) || $mime === '' || $mime === 'application/octet-stream') {
            return null;
        }

        $ext = MimeTypes::extFromMime($mime);

        return $ext === '' ? null : ['ext' => $ext, 'mime' => $mime];
    }
}
