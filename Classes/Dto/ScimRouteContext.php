<?php

declare(strict_types=1);

namespace Miniorange\Scim\Dto;

/**
 * Parsed SCIM HTTP route (resource type, optional resource id, legacy eID flag).
 */
final class ScimRouteContext
{
    private string $resource;
    private ?string $resourceId;
    private bool $isLegacyEid;

    public function __construct(
        string $resource,
        ?string $resourceId = null,
        bool $isLegacyEid = false,
    ) {
        $this->resource = $resource;
        $this->resourceId = $resourceId;
        $this->isLegacyEid = $isLegacyEid;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function isLegacyEid(): bool
    {
        return $this->isLegacyEid;
    }
}
