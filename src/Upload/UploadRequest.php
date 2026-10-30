<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

use Zeyen\Filse\Client;
use Zeyen\Filse\Exception\FilseException;
use Zeyen\Filse\Support\MimeTypes;

/**
 * 链式上传参数构建器。
 * 默认走「原始流」通道（适用于任意大小）；
 * asForm() 切换为 multipart 表单通道（服务端限制 ≤ CHUNK_THRESHOLD + 64KB）。
 */
final class UploadRequest
{
    private ?string $fileName = null;
    private ?string $customPath = null;
    private ?string $mimeType = null;
    private bool $asForm = false;
    private string $fileField = 'file';
    private readonly ?string $contents;

    public function __construct(
        private readonly Client  $client,
        private readonly ?string $filePath = null,
        ?string                  $contents = null,
    )
    {
        $this->contents = $contents;
    }

    public function fileName(?string $name): self
    {
        $this->fileName = $name === null || $name === '' ? null : $name;

        return $this;
    }

    public function path(?string $path): self
    {
        $this->customPath = $path === null || $path === '' ? null : $path;

        return $this;
    }

    public function mimeType(?string $mime): self
    {
        $this->mimeType = $mime === null || $mime === '' ? null : $mime;

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

            return (int)$size;
        }

        return strlen((string)$this->contents);
    }

    public function send(): UploadResult
    {
        $name = $this->fileName ?? ($this->filePath !== null ? basename($this->filePath) : 'file');
        $mime = $this->mimeType ?? MimeTypes::guess($name);

        if ($this->size() <= 0) {
            throw new FilseException('文件内容为空: ' . $name);
        }

        return $this->asForm ? $this->sendForm($name, $mime) : $this->sendRaw($name, $mime);
    }

    private function sendRaw(string $name, string $mime): UploadResult
    {
        $query = ['file_name' => $name, 'mime_type' => $mime];
        if ($this->customPath !== null) {
            $query['path'] = $this->customPath;
        }

        $contents = $this->filePath === null ? (string)$this->contents : null;

        return UploadResult::fromArray($this->client->rawUpload($query, $this->filePath, $contents));
    }

    private function sendForm(string $name, string $mime): UploadResult
    {
        $content = $this->filePath !== null ? file_get_contents($this->filePath) : (string)$this->contents;
        if ($content === false) {
            throw new FilseException('读取文件失败: ' . $this->filePath);
        }

        $boundary = '----FilseBoundary' . bin2hex(random_bytes(12));
        $eol = "\r\n";

        $body = '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="' . $this->fileField . '"; filename="' . self::escape($name) . '"' . $eol
            . 'Content-Type: ' . $mime . $eol . $eol
            . $content . $eol;

        $body .= '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="file_name"' . $eol . $eol
            . $name . $eol;

        if ($this->customPath !== null) {
            $body .= '--' . $boundary . $eol
                . 'Content-Disposition: form-data; name="path"' . $eol . $eol
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
