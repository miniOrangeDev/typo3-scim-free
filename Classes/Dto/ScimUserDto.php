<?php

declare(strict_types=1);

namespace Miniorange\Scim\Dto;

/**
 * Normalized SCIM Core User resource (subset used for TYPO3 provisioning).
 */
final class ScimUserDto
{
    private string $userName;
    private string $firstName;
    private string $lastName;
    private string $email;
    private bool $active;
    private ?string $externalId;

    /**
     * @var list<string>
     */
    private array $groups;

    /**
     * @var array<string, mixed>
     */
    private array $sourcePayload;

    /**
     * @param list<string> $groups External SCIM group display names or values from the payload.
     * @param array<string, mixed> $sourcePayload Raw SCIM User resource used for dynamic attribute extraction at sync time.
     */
    public function __construct(
        string $userName,
        string $firstName,
        string $lastName,
        string $email,
        bool $active,
        ?string $externalId = null,
        array $groups = [],
        array $sourcePayload = [],
    ) {
        $this->userName = $userName;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->active = $active;
        $this->externalId = $externalId;
        $this->groups = $groups;
        $this->sourcePayload = $sourcePayload;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * @return list<string>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSourcePayload(): array
    {
        return $this->sourcePayload;
    }

    public function getDisplayName(): string
    {
        $fullName = trim($this->firstName . ' ' . $this->lastName);

        return $fullName !== '' ? $fullName : $this->userName;
    }

    public function isDisabled(): bool
    {
        return !$this->active;
    }
}
