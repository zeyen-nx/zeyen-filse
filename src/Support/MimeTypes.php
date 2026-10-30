<?php


declare(strict_types=1);

namespace Zeyen\Filse\Support;

/**
 * 扩展名 -> MIME。仅用于给七牛声明 Content-Type，
 * 服务端在 fileName 为空时仍会用文件头魔数自行判断。
 */
final class MimeTypes
{
    private const MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'ico' => 'image/vnd.microsoft.icon',
        'svg' => 'image/svg+xml',
        'heic' => 'image/heic',
        'avif' => 'image/avif',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'amr' => 'audio/amr',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'gz' => 'application/gzip',
        'bz2' => 'application/x-bzip2',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'epub' => 'application/epub+zip',
        'apk' => 'application/vnd.android.package-archive',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'html' => 'text/html',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'sqlite' => 'application/x-sqlite3',
        'exe' => 'application/x-dosexec',
        'so' => 'application/x-executable',
    ];


    public static function guess(string $fileName): string
    {
        $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));

        return self::MAP[$ext] ?? 'application/octet-stream';
    }

    public static function fromExtension(string $ext): string
    {
        return self::MAP[strtolower(ltrim($ext, '.'))] ?? 'application/octet-stream';
    }

    public static function extFromMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if ($mime === '') {
            return '';
        }

        static $reverse = null;
        if ($reverse === null) {
            $reverse = [];
            foreach (self::MAP as $ext => $type) {
                $reverse[$type] ??= $ext;
            }
        }

        return $reverse[$mime] ?? '';
    }
}
