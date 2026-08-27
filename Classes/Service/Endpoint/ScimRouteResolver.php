<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use Miniorange\Scim\Dto\ScimRouteContext;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves clean SCIM paths such as /scim/v2/Users and legacy eID requests.
 */
final class ScimRouteResolver
{
    public const API_BASE_PATH = '/scim/v2';
    public const LEGACY_EID = 'scim_endpoint';

    public function resolve(ServerRequestInterface $request): ?ScimRouteContext
    {
        $legacyRoute = $this->resolveLegacyEidRoute($request);
        if ($legacyRoute !== null) {
            return $legacyRoute;
        }

        $path = $this->normalizePath($request->getUri()->getPath());
        $prefix = rtrim($this->resolveSiteBasePath($request) . self::API_BASE_PATH, '/');

        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            return null;
        }

        $remainder = ltrim(substr($path, strlen($prefix)), '/');
        if ($remainder === '') {
            return new ScimRouteContext('Root');
        }

        $segments = explode('/', $remainder);
        $resource = rawurldecode($segments[0]);
        $resourceId = isset($segments[1]) && $segments[1] !== ''
            ? rawurldecode($segments[1])
            : null;

        return new ScimRouteContext($resource, $resourceId);
    }

    public function isScimRequest(ServerRequestInterface $request): bool
    {
        return $this->resolve($request) !== null;
    }

    public function buildPublicBaseUrl(ServerRequestInterface $request): string
    {
        $siteBase = rtrim($this->resolveSiteBasePath($request), '/');
        $uri = $request->getUri();
        $authority = $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $authority .= ':' . $port;
        }

        return rtrim($uri->getScheme() . '://' . $authority, '/')
            . $siteBase
            . self::API_BASE_PATH
            . '/';
    }

    private function resolveLegacyEidRoute(ServerRequestInterface $request): ?ScimRouteContext
    {
        parse_str($request->getUri()->getQuery(), $query);

        if (($query['eID'] ?? '') !== self::LEGACY_EID) {
            return null;
        }

        return new ScimRouteContext('Users', null, true);
    }

    private function resolveSiteBasePath(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            return rtrim($site->getBase()->getPath(), '/');
        }

        return '';
    }

    private function normalizePath(string $path): string
    {
        $path = rawurldecode($path);
        $path = rtrim($path, '/') ?: '/';

        if (str_ends_with($path, '/index.php')) {
            $path = '/';
        }

        return $path;
    }
}
