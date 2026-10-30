<?php


declare(strict_types=1);

namespace Zeyen\Filse\Exception;

/**
 * 网关返回 code != 0，或响应不是合法 JSON。
 */
final class ApiException extends FilseException
{
    public function __construct(
        public readonly int     $apiCode,
        string                  $message,
        public readonly int     $httpStatus = 0,
        public readonly ?string $requestId = null,
        public readonly ?array  $payload = null,
    )
    {
        parent::__construct($message, $httpStatus);
    }

    public function isAuthError(): bool
    {
        return in_array($this->apiCode, [40100, 40300], true);
    }

    public function isQuotaExceeded(): bool
    {
        return $this->apiCode === 42900;
    }

    public function isPayloadTooLarge(): bool
    {
        return $this->apiCode === 41300;
    }
}
