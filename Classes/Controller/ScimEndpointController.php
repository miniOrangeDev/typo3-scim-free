<?php

declare(strict_types=1);

namespace Miniorange\Scim\Controller;

use Miniorange\Scim\Http\ScimEidDispatcher;
use Miniorange\Scim\Service\Endpoint\ScimRequestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * SCIM API entry: clean /scim/v2/* (middleware) and legacy index.php?eID=scim_endpoint.
 */
class ScimEndpointController
{
    private ?ScimRequestHandler $requestHandler = null;

    public function __construct(?ScimRequestHandler $requestHandler = null)
    {
        $this->requestHandler = $requestHandler;
    }

    /**
     * PSR-15 middleware entry — returns a response without terminating the process.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return ScimEidDispatcher::withScopedErrorHandling(
                fn (): ResponseInterface => $this->getRequestHandler()->handle($request)
            );
        } catch (Throwable $exception) {
            return $this->getRequestHandler()->createDebugResponse($exception, 400);
        }
    }

    /**
     * Legacy eID entry invoked by {@see ScimEidDispatcher::dispatch()}.
     */
    public function processRequest(
        ?ServerRequestInterface $request = null,
        ?ResponseInterface $response = null
    ): void {
        try {
            $request = $request ?? $this->resolveRequest();
            $response = $response ?? $this->handle($request);
            ScimEidDispatcher::sendResponse($response);
        } catch (Throwable $exception) {
            ScimEidDispatcher::emitDebugJsonAndExit($exception, 400);
        }

        exit;
    }

    private function resolveRequest(): ServerRequestInterface
    {
        if (isset($GLOBALS['TYPO3_REQUEST']) && $GLOBALS['TYPO3_REQUEST'] instanceof ServerRequestInterface) {
            return $GLOBALS['TYPO3_REQUEST'];
        }

        return ServerRequestFactory::fromGlobals();
    }

    private function getRequestHandler(): ScimRequestHandler
    {
        return $this->requestHandler ??= GeneralUtility::makeInstance(ScimRequestHandler::class);
    }
}
