<?php

declare(strict_types=1);

namespace Miniorange\Scim\Helper;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\Renderer\ListRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MoUtilities
{
    public static function getBaseUrl(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $uri = $request->getUri();
            return rtrim((string)$uri->getScheme() . '://' . $uri->getHost(), '/');
        }

        $isHttps = strcasecmp(self::stringFromMixed($_SERVER['HTTPS'] ?? null), 'on') === 0;
        $host = self::stringFromMixed($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost');

        return ($isHttps ? 'https' : 'http') . '://' . rtrim($host, '/');
    }

    /**
     * Safely converts a value to a string.
     * Non-scalar values return the default to prevent conversion warnings.
     */
    public static function stringFromMixed(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * Safely coerces request/config-derived data of unknown static type to an int.
     * See {@see self::stringFromMixed()} for why a blind (int) cast is unsafe here.
     */
    public static function intFromMixed(mixed $value, int $default = 0): int
    {
        return is_scalar($value) ? (int)$value : $default;
    }

    public static function showErrorFlashMessage(string $message, string $header = 'ERROR'): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $header,
            ContextualFeedbackSeverity::ERROR
        );
        $out = GeneralUtility::makeInstance(ListRenderer::class)->render([$flashMessage]);
        echo $out;
    }

    public static function showSuccessFlashMessage(string $message, string $header = 'Success'): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $header,
            ContextualFeedbackSeverity::OK
        );
        $out = GeneralUtility::makeInstance(ListRenderer::class)->render([$flashMessage]);
        echo $out;
    }

    public static function getTypo3Version(): string
    {
        return (new Typo3Version())->getVersion();
    }

    public static function clearFlashMessages(): void
    {
        // Flash messages are rendered inline in this module; no-op for API parity with SSO.
    }
}
