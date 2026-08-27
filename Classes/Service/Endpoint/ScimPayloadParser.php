<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use JsonException;
use Miniorange\Scim\Dto\ScimUserDto;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ScimPayloadParser
{
    private ?ScimAttributeMapper $attributeMapper = null;

    public function __construct(?ScimAttributeMapper $attributeMapper = null)
    {
        $this->attributeMapper = $attributeMapper;
    }

    /**
     * @throws JsonException
     */
    public function parse(string $rawBody): ScimUserDto
    {
        if (trim($rawBody) === '') {
            throw new JsonException('SCIM request body is empty.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        try {
            return $this->getAttributeMapper()->mapToUserDto($data);
        } catch (\InvalidArgumentException $exception) {
            throw new JsonException($exception->getMessage());
        }
    }

    private function getAttributeMapper(): ScimAttributeMapper
    {
        return $this->attributeMapper ??= GeneralUtility::makeInstance(ScimAttributeMapper::class);
    }
}
