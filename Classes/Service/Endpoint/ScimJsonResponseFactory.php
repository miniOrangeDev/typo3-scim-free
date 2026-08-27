<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ScimJsonResponseFactory
{
    private const SCIM_CONTENT_TYPE = 'application/scim+json; charset=utf-8';

    private ?ResponseFactoryInterface $responseFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;

    public function __construct(
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function success(array $payload, int $statusCode = 200): ResponseInterface
    {
        return $this->json($payload, $statusCode);
    }

    public function noContent(): ResponseInterface
    {
        return $this->getResponseFactory()
            ->createResponse(204)
            ->withHeader('Content-Type', self::SCIM_CONTENT_TYPE);
    }

    public function unauthorized(string $detail = 'Invalid or missing Bearer token.'): ResponseInterface
    {
        // RFC 6750 §3 recommends a WWW-Authenticate challenge on 401 responses to bearer-token
        // protected resources.
        return $this->error($detail, 401)->withHeader('WWW-Authenticate', 'Bearer realm="SCIM"');
    }

    public function badRequest(string $detail): ResponseInterface
    {
        return $this->error($detail, 400);
    }

    public function forbidden(string $detail): ResponseInterface
    {
        return $this->error($detail, 403);
    }

    public function conflict(string $detail, ?string $resourceId = null): ResponseInterface
    {
        $payload = [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $detail,
            'status' => '409',
        ];

        if ($resourceId !== null) {
            $payload['id'] = $resourceId;
        }

        return $this->json($payload, 409);
    }

    public function methodNotAllowed(string $detail = 'HTTP method is not supported.'): ResponseInterface
    {
        return $this->error($detail, 405);
    }

    public function error(string $detail, int $statusCode): ResponseInterface
    {
        return $this->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $detail,
            'status' => (string)$statusCode,
        ], $statusCode);
    }

    /**
     * Debug-friendly SCIM/JSON error including stack trace, only exposed outside production.
     */
    public function debugThrowable(Throwable $exception, int $statusCode = 400): ResponseInterface
    {
        if (!Environment::getContext()->isDevelopment()) {
            return $this->error('An unexpected error occurred while processing the SCIM request.', $statusCode);
        }

        return new JsonResponse([
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $exception->getMessage(),
            'status' => (string)$statusCode,
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $statusCode): ResponseInterface
    {
        $body = $this->getStreamFactory()->createStream(
            (string)json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        return $this->getResponseFactory()
            ->createResponse($statusCode)
            ->withHeader('Content-Type', self::SCIM_CONTENT_TYPE)
            ->withBody($body);
    }

    private function getResponseFactory(): ResponseFactoryInterface
    {
        if ($this->responseFactory === null) {
            $this->responseFactory = GeneralUtility::makeInstance(ResponseFactoryInterface::class);
        }

        return $this->responseFactory;
    }

    private function getStreamFactory(): StreamFactoryInterface
    {
        if ($this->streamFactory === null) {
            $this->streamFactory = GeneralUtility::makeInstance(StreamFactoryInterface::class);
        }

        return $this->streamFactory;
    }
}
