<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

/**
 * Resolves dot/bracket paths (e.g. name.givenName, emails[0].value) from SCIM payload arrays.
 */
final class ScimAttributePathResolver
{
    /**
     * @param array<string, mixed> $data
     */
    public function resolve(array $data, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $tokens = $this->tokenizePath($path);
        if ($tokens === []) {
            return null;
        }

        $current = $data;
        foreach ($tokens as $token) {
            if (is_int($token)) {
                if (!is_array($current) || !array_key_exists($token, $current)) {
                    return null;
                }
                $current = $current[$token];
                continue;
            }

            if (!is_array($current) || !array_key_exists($token, $current)) {
                return null;
            }
            $current = $current[$token];
        }

        return $current;
    }

    /**
     * @return list<string|int>
     */
    private function tokenizePath(string $path): array
    {
        preg_match_all('/([a-zA-Z0-9_$-]+)|\[(\d+)\]/', $path, $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '') {
                $tokens[] = (int)$match[2];
                continue;
            }
            if (($match[1] ?? '') !== '') {
                $tokens[] = $match[1];
            }
        }

        return $tokens;
    }
}
