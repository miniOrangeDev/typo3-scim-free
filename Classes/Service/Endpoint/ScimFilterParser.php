<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

/**
 * Parses a subset of SCIM filter expressions used by IDP during user lookup.
 */
final class ScimFilterParser
{
    private const SUPPORTED_ATTRIBUTES = ['username', 'emails.value', 'email', 'externalid'];

    /**
     * Returns null only when no filter was supplied. A non-empty filter that cannot be
     * parsed, or that targets an unsupported attribute, throws instead — so callers can
     * distinguish "no filter" (search everything) from "bad filter" (reject the request)
     * rather than silently treating both the same way.
     *
     * @return array{attribute: string, value: string}|null
     * @throws \InvalidArgumentException When a non-empty filter is malformed or unsupported.
     */
    public function parseEqualityFilter(?string $filter): ?array
    {
        if ($filter === null) {
            return null;
        }

        $filter = trim($filter);
        if ($filter === '') {
            return null;
        }

        if (!preg_match(
            '/^([a-zA-Z][\w.]*)\s+eq\s+("((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\')$/',
            $filter,
            $matches
        )) {
            throw new \InvalidArgumentException(sprintf('Unsupported or invalid SCIM filter expression: "%s".', $filter));
        }

        $attribute = strtolower($matches[1]);
        // Use the opening quote to detect the matched variant, preserving empty quoted values.
        $isDoubleQuoted = str_starts_with($matches[2], '"');
        $value = $isDoubleQuoted ? stripcslashes($matches[3] ?? '') : ($matches[4] ?? '');

        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported SCIM filter attribute: "%s".', $attribute));
        }

        return [
            'attribute' => $attribute,
            'value' => $value,
        ];
    }
}
