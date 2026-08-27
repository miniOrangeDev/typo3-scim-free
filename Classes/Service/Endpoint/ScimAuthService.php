<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use Miniorange\Scim\Helper\Constants;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Helper\ScimConfig;
use Psr\Http\Message\ServerRequestInterface;

final class ScimAuthService
{
    public function isAuthorized(ServerRequestInterface $request): bool
    {
        $storedToken = trim(ScimConfig::fetch(Constants::SCIM_BEARER_TOKEN) ?? '');
        if ($storedToken === '') {
            return false;
        }

        $providedToken = $this->extractBearerToken($request);
        if ($providedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $providedToken);
    }

    private function extractBearerToken(ServerRequestInterface $request): string
    {
        $authorization = $request->getHeaderLine('Authorization');
        if ($authorization === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authorization = MoUtilities::stringFromMixed($_SERVER['HTTP_AUTHORIZATION']);
        }
        if ($authorization === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authorization = MoUtilities::stringFromMixed($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            return '';
        }

        return trim($matches[1]);
    }
}
