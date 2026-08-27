<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use Miniorange\Scim\Helper\Constants;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Helper\ScimConfig;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ScimProvisionSettings
{
    public function isTargetEnabled(string $target): bool
    {
        $raw = trim(ScimConfig::fetch(Constants::PROVISION_TARGET) ?? '');
        if ($raw === '') {
            return false;
        }

        if (!str_contains($raw, ',')) {
            return $raw === $target;
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)));

        return in_array($target, $parts, true);
    }

    public function isCreateEnabled(): bool
    {
        return (ScimConfig::fetch(Constants::SYNC_CREATE_USERS) ?? '0') === '1';
    }

    public function isUpdateEnabled(): bool
    {
        return (ScimConfig::fetch(Constants::SYNC_UPDATE_USERS) ?? '0') === '1';
    }

    public function isDisableEnabled(): bool
    {
        return (ScimConfig::fetch(Constants::SYNC_DISABLE_USERS) ?? '0') === '1';
    }

    public function isDeleteOnDeactivationEnabled(): bool
    {
        return (ScimConfig::fetch(Constants::DELETE_ON_DEACTIVATION) ?? '0') === '1';
    }

    /**
     * @return list<string>
     */
    public function getEnabledTargets(): array
    {
        $targets = [];
        if ($this->isTargetEnabled('fe_users')) {
            $targets[] = 'fe_users';
        }
        if ($this->isTargetEnabled('be_users')) {
            $targets[] = 'be_users';
        }

        return $targets;
    }

    public function getFrontendUsersStoragePid(): int
    {
        return $this->resolveStoragePid(
            Constants::FE_USERS_STORAGE_PID,
            'feUsersStoragePid'
        );
    }

    public function getBackendUsersStoragePid(): int
    {
        return $this->resolveStoragePid(
            Constants::BE_USERS_STORAGE_PID,
            'beUsersStoragePid'
        );
    }

    private function resolveStoragePid(string $configColumn, string $extensionConfigKey): int
    {
        $configured = trim(ScimConfig::fetch($configColumn) ?? '');
        if ($configured !== '' && (int)$configured >= 0) {
            return (int)$configured;
        }

        try {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            $extensionPid = MoUtilities::intFromMixed($extensionConfiguration->get('scim_user_provisioning', $extensionConfigKey));
            if ($extensionPid >= 0) {
                return $extensionPid;
            }
        } catch (\Throwable) {
            // Extension configuration is optional.
        }

        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $extensionsConfig = is_array($typo3ConfVars) ? ($typo3ConfVars['EXTENSIONS'] ?? null) : null;
        $scimConfig = is_array($extensionsConfig) ? ($extensionsConfig['scim_user_provisioning'] ?? null) : null;
        $globalPidValue = is_array($scimConfig) ? ($scimConfig[$extensionConfigKey] ?? null) : null;

        return max(0, MoUtilities::intFromMixed($globalPidValue));
    }
}
