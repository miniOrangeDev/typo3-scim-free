<?php

declare(strict_types=1);

namespace Miniorange\Scim\Middleware;

use Miniorange\Scim\Controller\ScimEndpointController;
use Miniorange\Scim\Service\Endpoint\ScimRouteResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Intercepts /scim/v2/* before TYPO3 page routing so IDP can append /Users, /Groups, etc.
 */
final class ScimApiMiddleware implements MiddlewareInterface
{
    private ?ScimRouteResolver $routeResolver = null;
    private ?ScimEndpointController $endpointController = null;

    public function __construct(
        ?ScimRouteResolver $routeResolver = null,
        ?ScimEndpointController $endpointController = null,
    ) {
        $this->routeResolver = $routeResolver;
        $this->endpointController = $endpointController;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->getRouteResolver()->isScimRequest($request)) {
            return $handler->handle($request);
        }

        return $this->getEndpointController()->handle($request);
    }

    private function getRouteResolver(): ScimRouteResolver
    {
        return $this->routeResolver ??= GeneralUtility::makeInstance(ScimRouteResolver::class);
    }

    private function getEndpointController(): ScimEndpointController
    {
        return $this->endpointController ??= GeneralUtility::makeInstance(ScimEndpointController::class);
    }
}
