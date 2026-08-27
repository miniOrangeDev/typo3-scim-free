<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service;

use Miniorange\Scim\Helper\Constants;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Helper\ScimConfig;

final class ScimMappingConfigurationService
{
    /**
     * @return array<string, string>
     */
    public function getDefaultAttributeMapping(): array
    {
        return [
            'username' => 'userName',
            'email' => 'emails[0].value',
            'first_name' => 'name.givenName',
            'last_name' => 'name.familyName',
            'group_role' => 'groups',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAttributeMapping(): array
    {
        return $this->getCoreAttributeMapping();
    }

    /**
     * @return array<string, string>
     */
    public function getCoreAttributeMapping(): array
    {
        $defaults = $this->getDefaultAttributeMapping();
        $stored = ScimConfig::fetchJson(Constants::ATTRIBUTE_MAPPING);
        $resolved = [];

        foreach ($defaults as $key => $default) {
            $value = trim(MoUtilities::stringFromMixed($stored[$key] ?? null));
            $resolved[$key] = $value !== '' ? $value : $default;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    public function getStoredCoreAttributeMapping(): array
    {
        $defaults = $this->getDefaultAttributeMapping();
        $stored = ScimConfig::fetchJson(Constants::ATTRIBUTE_MAPPING);
        $values = [];

        foreach (array_keys($defaults) as $key) {
            $values[$key] = trim(MoUtilities::stringFromMixed($stored[$key] ?? null));
        }

        return $values;
    }

    /**
     * @param array<mixed> $post Raw $_POST data; keys are not guaranteed to be strings.
     */
    public function saveAttributeMapping(array $post): void
    {
        $mapping = [
            'username' => trim(MoUtilities::stringFromMixed($post['mo_scim_attr_username'] ?? null)),
            'email' => trim(MoUtilities::stringFromMixed($post['mo_scim_attr_email'] ?? null)),
            'first_name' => trim(MoUtilities::stringFromMixed($post['mo_scim_attr_first_name'] ?? null)),
            'last_name' => trim(MoUtilities::stringFromMixed($post['mo_scim_attr_last_name'] ?? null)),
            'group_role' => trim(MoUtilities::stringFromMixed($post['mo_scim_attr_group_role'] ?? null)),
        ];

        ScimConfig::updateJson(Constants::ATTRIBUTE_MAPPING, $mapping);
        MoUtilities::showSuccessFlashMessage('SCIM attribute mapping has been saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMappingConfigurationForView(): array
    {
        ScimConfig::ensureRow();

        $storedAttributeMapping = $this->getStoredCoreAttributeMapping();
        $defaultAttributeMapping = $this->getDefaultAttributeMapping();

        return [
            'attr_username' => $storedAttributeMapping['username'],
            'attr_email' => $storedAttributeMapping['email'],
            'attr_first_name' => $storedAttributeMapping['first_name'],
            'attr_last_name' => $storedAttributeMapping['last_name'],
            'attr_group_role' => $storedAttributeMapping['group_role'],
            'attr_username_placeholder' => $defaultAttributeMapping['username'],
            'attr_email_placeholder' => $defaultAttributeMapping['email'],
            'attr_first_name_placeholder' => $defaultAttributeMapping['first_name'],
            'attr_last_name_placeholder' => $defaultAttributeMapping['last_name'],
            'attr_group_role_placeholder' => $defaultAttributeMapping['group_role'],
        ];
    }
}
