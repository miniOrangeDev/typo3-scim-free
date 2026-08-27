<?php

declare(strict_types=1);

namespace Miniorange\Scim\Http;

use Miniorange\Scim\Controller\ScimEndpointController;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Top-level eID entry: catches failures before/after controller DI and stops FE bootstrap.
 */
final class ScimEidDispatcher
{
    /**
     * Registered in ext_localconf.php as FE eID_include target.
     */
    public static function dispatch(): void
    {
        try {
            self::withScopedErrorHandling(static function (): void {
                $controller = GeneralUtility::makeInstance(ScimEndpointController::class);
                $controller->processRequest();
            });
        } catch (Throwable $exception) {
            self::emitDebugJsonAndExit($exception, 400);
        }
    }

    /**
     * Temporarily converts PHP errors into exceptions for the callback.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withScopedErrorHandling(callable $callback): mixed
    {
        set_error_handler(self::phpErrorToException(...));

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private static function phpErrorToException(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public static function emitDebugJsonAndExit(Throwable $exception, int $statusCode = 400): never
    {
        self::flushOutputBuffers();

        $payload = [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $exception->getMessage(),
            'status' => (string)$statusCode,
        ];

        if (Environment::getContext()->isDevelopment()) {
            $payload['error'] = $exception->getMessage();
            $payload['trace'] = $exception->getTraceAsString();
        } else {
            $payload['error'] = 'An unexpected error occurred while processing the SCIM request.';
        }

        self::sendResponse(new JsonResponse($payload, $statusCode));
        exit;
    }

    public static function sendResponse(ResponseInterface $response): void
    {
        self::flushOutputBuffers();

        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        $body = (string)$response->getBody();
        if ($body !== '') {
            echo $body;
        }
    }

    private static function flushOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
