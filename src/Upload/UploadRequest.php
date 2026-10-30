<?php

declare(strict_types=1);

namespace Zeyen\Filse\Upload;

use Zeyen\Filse\Client;
use Zeyen\Filse\Exception\FilseException;
use Zeyen\Filse\Support\MimeSniffer;
use Zeyen\Filse\Support\MimeTypes;

/**
 * 链式上传参数构建器。
 *
 * 参数按需发送：
 *   - 未调用 fileName() → 「原始流」下不传 file_name（服务端嗅探 + 随机命名）
 *   - 未调用 path()     → 不传 path
 *   - 未调用 mimeType() → 不传 mime_type，Content-Type 用文件头嗅探结果
 *
 * 文件名后缀一律以文件内容为准：客户端传来的名字后缀缺失或与真实格式不符时，
 * SDK 在本机就纠正（例如 xxx.mpbtc5 + JPEG 内容 → xxx.jpg），
 * 避免服务端把错误后缀写进对象 key。
 */
final class UploadRequest
{
    private ?string $fileName = null;
    private ?string $customPath = null;
    private ?string $mimeType = null;
    private bool $asForm = false;
    private bool $preserveName = false;
    private string $fileField = 'file';
    private readonly ?string $contents;

    public function __construct(
        private readonly Client $client,
        private readonly ?string $filePath = null,
        ?string $contents = null,
    ) {
        $this->contents = $contents;
    }

    public function fileName(?string $name): self
    {
        $this->fileName = $name === null || trim($name) === '' ? null : $name;

        return $this;
    }

    public function path(?string $path): self
    {
        $this->customPath = $path === null || trim($path) === '' ? null : $path;

        return $this;
    }

    public function mimeType(?string $mime): self
    {
        $this->mimeType = $mime === null || trim($mime) === '' ? null : $mime;

        return $this;
    }

    /**
     * 完全保留传入的文件名，不做后缀纠正（需要原样落库时使用）。
     */
    public function preserveFileName(bool $preserve = true): self
    {
        $this->preserveName = $preserve;

        return $this;
    }

    public function asForm(bool $form = true): self
    {
        $this->asForm = $form;

        return $this;
    }

    public function asRaw(): self
    {
        $this->asForm = false;

        return $this;
    }

    public function fileField(string $field): self
    {
        $this->fileField = $field;

        return $this;
    }

    public function size(): int
    {
        if ($this->filePath !== null) {
            $size = @filesize($this->filePath);
            if ($size === false) {
                throw new FilseException('无法读取文件大小: ' . $this->filePath);
            }

            return (int) $size;
        }

        return strlen((string) $this->contents);
    }

    public function send(): UploadResult
    {
        if ($this->size() <= 0) {
            throw new FilseException(
                '文件内容为空' . ($this->filePath !== null ? ': ' . $this->filePath : '')
            );
        }

        $detected = MimeSniffer::detect(
            $this->filePath,
            $this->filePath === null ? (string) $this->contents : null
        );

        $name = $this->resolveName($detected);
        $contentType = $this->mimeType ?? $detected['mime'] ?? MimeTypes::guess($name);

        return $this->asForm
            ? $this->sendForm($name, $contentType)
            : $this->sendRaw($name, $contentType);
    }

    /**
     * 需要发出去的文件名：显式 fileName() 优先，否则用本地文件名。
     */
    private function resolveName(?array $detected): string
    {
        $base = $this->fileName
            ?? ($this->filePath !== null ? basename($this->filePath) : 'file');

        if ($this->preserveName) {
            return $base;
        }

        return MimeSniffer::alignName($base, $detected);
    }

    private function sendRaw(string $name, string $contentType): UploadResult
    {
        $query = [];
        // 只有显式指定过文件名才传，避免覆盖服务端的自动命名
        if ($this->fileName !== null) {
            $query['file_name'] = $name;
        }
        if ($this->customPath !== null) {
            $query['path'] = $this->customPath;
        }
        if ($this->mimeType !== null) {
            $query['mime_type'] = $this->mimeType;
        }

        $contents = $this->filePath === null ? (string) $this->contents : null;

        return UploadResult::fromArray(
            $this->client->rawUpload($query, $this->filePath, $contents, $contentType)
        );
    }

    private function sendForm(string $name, string $contentType): UploadResult
    {
        $content = $this->filePath !== null ? file_get_contents($this->filePath) : (string) $this->contents;
        if ($content === false) {
            throw new FilseException('读取文件失败: ' . $this->filePath);
        }

        // file 字段必发，且 part 的 filename 必须非空，否则服务端会当作普通字段
        $boundary = '----FilseBoundary' . bin2hex(random_bytes(12));
        $eol = "\r\n";

        $body = '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="' . $this->fileField . '"; filename="' . self::escape($name) . '"' . $eol
            . 'Content-Type: ' . $contentType . $eol
            . $eol
            . $content . $eol;

        if ($this->customPath !== null) {
            $body .= '--' . $boundary . $eol
                . 'Content-Disposition: form-data; name="path"' . $eol
                . $eol
                . $this->customPath . $eol;
        }

        $body .= '--' . $boundary . '--' . $eol;

        return UploadResult::fromArray($this->client->multipartUpload($body, $boundary));
    }

    private static function escape(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '', ''], $value);
    }
}
