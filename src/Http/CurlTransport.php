<?php


declare(strict_types=1);

namespace Zeyen\Filse\Http;

use Zeyen\Filse\Exception\TransportException;

/**
 * 默认传输层：ext-curl，支持从文件句柄流式上传（大文件不占内存）。
 */
final class CurlTransport implements TransportInterface
{
    public function send(HttpRequest $request): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('无法初始化 cURL');
        }

        $headerLines = [];
        $hasExpect = false;
        $hasContentLength = false;
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
            if (strcasecmp($name, 'Expect') === 0) {
                $hasExpect = true;
            }
            if (strcasecmp($name, 'Content-Length') === 0) {
                $hasContentLength = true;
            }
        }
        if (!$hasExpect) {
            // 禁用 100-continue，避免部分网络环境下多等 1 秒
            $headerLines[] = 'Expect:';
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($request->method),
            CURLOPT_TIMEOUT => max(1, (int)ceil($request->timeout)),
            CURLOPT_CONNECTTIMEOUT => max(1, (int)ceil($request->connectTimeout)),
            CURLOPT_SSL_VERIFYPEER => $request->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $request->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        $fp = null;
        if ($request->fileStream !== null || $request->filePath !== null) {
            $fp = $request->fileStream ?? fopen((string)$request->filePath, 'rb');
            if ($fp === false) {
                curl_close($ch);
                throw new TransportException('无法打开待上传文件: ' . $request->filePath);
            }
            $size = $request->contentLength
                ?? ($request->filePath !== null ? (int)filesize($request->filePath) : 0);

            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $fp;
            $options[CURLOPT_INFILESIZE] = $size;
            if (!$hasContentLength) {
                $headerLines[] = 'Content-Length: ' . $size;
            }
        } elseif ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } elseif (in_array(strtoupper($request->method), ['POST', 'PUT', 'PATCH'], true)) {
            $options[CURLOPT_POSTFIELDS] = '';
        }

        $options[CURLOPT_HTTPHEADER] = $headerLines;

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);

        if ($raw === false) {
            $message = curl_error($ch);
            $code = curl_errno($ch);
            curl_close($ch);
            if (is_resource($fp)) {
                fclose($fp);
            }
            throw new TransportException("HTTP 请求失败({$code}): {$message}", $code);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (is_resource($fp)) {
            fclose($fp);
        }

        $headerText = (string)substr($raw, 0, $headerSize);
        $body = (string)substr($raw, $headerSize);

        return new HttpResponse($status, $body, self::parseHeaders($headerText));
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $headerText): array
    {
        $headers = [];
        foreach (preg_split("/\r\n/", $headerText) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, 'HTTP/')) {
                // 多个响应头块（如 100-continue）时重置
                $headers = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        return $headers;
    }
}
