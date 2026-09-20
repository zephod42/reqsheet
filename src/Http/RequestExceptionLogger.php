<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class RequestExceptionLogger
{
    public static function requestId(?string $candidate = null): string
    {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && preg_match('/\A[A-Za-z0-9._-]{8,64}\z/', $candidate) === 1) return $candidate;
        return bin2hex(random_bytes(8));
    }

    public static function log(string $route, \Throwable $exception, string $requestId): void
    {
        error_log(self::format($route, $exception, $requestId));
    }

    public static function format(string $route, \Throwable $exception, string $requestId): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $exception->getMessage()) ?? 'Unavailable exception message';
        $message = preg_replace(
            '/\b(password|passwd|secret|token|recovery[_ -]?key|cookie|authorization|credential)(\s*[=:]\s*)([^,;\s]+)/i',
            '$1$2[redacted]',
            $message,
        ) ?? 'Unavailable exception message';
        if (strlen($message) > 500) $message = substr($message, 0, 500) . '…';

        return sprintf(
            '[reqsheet] request_id=%s route=%s exception=%s message=%s origin=%s:%d',
            self::field($requestId),
            self::field($route),
            $exception::class,
            $message,
            $exception->getFile(),
            $exception->getLine(),
        );
    }

    private static function field(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._\/-]/', '_', $value) ?? 'unknown';
    }
}
