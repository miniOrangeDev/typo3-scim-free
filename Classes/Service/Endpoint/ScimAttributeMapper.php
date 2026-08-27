<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use Miniorange\Scim\Dto\ScimUserDto;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Service\ScimMappingConfigurationService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Maps SCIM JSON payloads to normalized user fields using admin attribute_mapping configuration.
 */
final class ScimAttributeMapper
{
    private ?ScimMappingConfigurationService $mappingConfigurationService = null;
    private ?ScimAttributePathResolver $pathResolver = null;

    public function __construct(
        ?ScimMappingConfigurationService $mappingConfigurationService = null,
        ?ScimAttributePathResolver $pathResolver = null,
    ) {
        $this->mappingConfigurationService = $mappingConfigurationService;
        $this->pathResolver = $pathResolver;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function mapToUserDto(array $payload): ScimUserDto
    {
        $mapping = $this->getMappingConfigurationService()->getAttributeMapping();

        $userName = $this->resolveMappedString(
            $payload,
            (string)($mapping['username'] ?? ''),
            fn(array $data): string => $this->fallbackUsername($data)
        );

        if ($userName === '') {
            throw new \InvalidArgumentException('SCIM userName is required.');
        }

        $email = $this->resolveMappedString(
            $payload,
            (string)($mapping['email'] ?? ''),
            fn(array $data): string => $this->fallbackEmail($data, $userName)
        );

        $firstName = $this->resolveMappedString(
            $payload,
            (string)($mapping['first_name'] ?? ''),
            fn(array $data): string => $this->fallbackFirstName($data)
        );

        $lastName = $this->resolveMappedString(
            $payload,
            (string)($mapping['last_name'] ?? ''),
            fn(array $data): string => $this->fallbackLastName($data)
        );

        $groups = $this->resolveMappedGroups(
            $payload,
            (string)($mapping['group_role'] ?? '')
        );

        $active = $this->resolveActiveFlag($payload);
        $externalId = $this->resolveExternalId($payload);

        return new ScimUserDto(
            $userName,
            $firstName,
            $lastName,
            $email,
            $active,
            $externalId,
            $groups,
            $payload,
        );
    }

    /**
     * Database column map for fe_users / be_users core identity fields.
     *
     * @return array{username: string, email: string, first_name: string, last_name: string}
     */
    public function buildCoreUserColumns(ScimUserDto $user): array
    {
        return [
            'username' => $user->getUserName(),
            'email' => $user->getEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>): string $fallback
     */
    private function resolveMappedString(array $payload, string $configuredPath, callable $fallback): string
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath !== '') {
            $resolved = $this->getPathResolver()->resolve($payload, $configuredPath);
            $value = $this->coerceToString($resolved);
            if ($value !== '') {
                return $value;
            }
        }

        return trim($fallback($payload));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function resolveMappedGroups(array $payload, string $configuredPath): array
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath !== '') {
            $resolved = $this->getPathResolver()->resolve($payload, $configuredPath);
            $groups = $this->normalizeGroupsValue($resolved);
            if ($groups !== []) {
                return $groups;
            }
        }

        return $this->fallbackGroups($payload);
    }

    private function coerceToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) || is_numeric($value)) {
            return trim((string)$value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function normalizeGroupsValue(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $groups = [];
        foreach ($value as $entry) {
            if (is_string($entry) || is_numeric($entry)) {
                $name = trim((string)$entry);
                if ($name !== '') {
                    $groups[] = $name;
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            foreach (['display', 'value', '$ref'] as $key) {
                $candidate = trim(MoUtilities::stringFromMixed($entry[$key] ?? null));
                if ($candidate !== '') {
                    $groups[] = $candidate;
                    break;
                }
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fallbackUsername(array $data): string
    {
        return trim(MoUtilities::stringFromMixed($data['userName'] ?? null));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fallbackEmail(array $data, string $userName): string
    {
        $email = $this->extractPrimaryEmail($data);
        if ($email === '' && str_contains($userName, '@')) {
            return $userName;
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fallbackFirstName(array $data): string
    {
        $name = is_array($data['name'] ?? null) ? $data['name'] : [];

        return trim(MoUtilities::stringFromMixed($name['givenName'] ?? $data['givenName'] ?? null));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fallbackLastName(array $data): string
    {
        $name = is_array($data['name'] ?? null) ? $data['name'] : [];

        return trim(MoUtilities::stringFromMixed($name['familyName'] ?? $data['familyName'] ?? null));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function fallbackGroups(array $data): array
    {
        if (!isset($data['groups']) || !is_array($data['groups'])) {
            return [];
        }

        return $this->normalizeGroupsValue($data['groups']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractPrimaryEmail(array $data): string
    {
        if (!isset($data['emails']) || !is_array($data['emails'])) {
            return trim(MoUtilities::stringFromMixed($data['email'] ?? null));
        }

        foreach ($data['emails'] as $emailEntry) {
            if (!is_array($emailEntry)) {
                continue;
            }
            $value = trim(MoUtilities::stringFromMixed($emailEntry['value'] ?? null));
            if ($value === '') {
                continue;
            }
            $type = strtolower(MoUtilities::stringFromMixed($emailEntry['type'] ?? null));
            $primary = $emailEntry['primary'] ?? false;
            if ($primary === true || $type === 'work' || $type === '') {
                return $value;
            }
        }

        foreach ($data['emails'] as $emailEntry) {
            if (is_array($emailEntry) && ($emailEntry['value'] ?? '') !== '') {
                return trim(MoUtilities::stringFromMixed($emailEntry['value']));
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveActiveFlag(array $data): bool
    {
        if (!array_key_exists('active', $data)) {
            return true;
        }

        $value = $data['active'];
        if (!is_scalar($value)) {
            // Reject non-scalar values (e.g. arrays or objects).
            throw new \InvalidArgumentException('SCIM "active" must be a boolean value.');
        }

        $active = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            // Reject values that cannot be interpreted as booleans.
            throw new \InvalidArgumentException('SCIM "active" must be a boolean value.');
        }

        return $active;
    }

    /**
     * Only the SCIM `externalId` attribute is trusted as the durable IdP identity reference.
     * `id` is server-assigned per the SCIM spec and must never be accepted from client input
     * as a stand-in for it.
     *
     * @param array<string, mixed> $data
     */
    private function resolveExternalId(array $data): ?string
    {
        $externalId = isset($data['externalId']) ? trim(MoUtilities::stringFromMixed($data['externalId'])) : null;

        return ($externalId === '' || $externalId === null) ? null : $externalId;
    }

    private function getMappingConfigurationService(): ScimMappingConfigurationService
    {
        return $this->mappingConfigurationService ??= GeneralUtility::makeInstance(ScimMappingConfigurationService::class);
    }

    private function getPathResolver(): ScimAttributePathResolver
    {
        return $this->pathResolver ??= GeneralUtility::makeInstance(ScimAttributePathResolver::class);
    }
}
