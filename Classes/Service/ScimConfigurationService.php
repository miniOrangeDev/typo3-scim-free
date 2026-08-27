<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service;

use Miniorange\Scim\Helper\AESEncryption;
use Miniorange\Scim\Helper\Constants;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Helper\ScimConfig;
use Miniorange\Scim\Service\Endpoint\ScimProvisionSettings;
use Miniorange\Scim\Service\Endpoint\ScimRouteResolver;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ScimConfigurationService
{
    public const OPTION_REGENERATE_TOKEN = 'mo_scim_regenerate_token';
    public const OPTION_SAVE_AZURE = 'mo_scim_save_azure_config';
    public const OPTION_SAVE_SYNC = 'mo_scim_save_sync_settings';
    public const OPTION_SAVE_ATTRIBUTE_MAPPING = 'mo_scim_save_attribute_mapping';

    private ?ScimProvisionSettings $provisionSettings = null;
    private ?ScimMappingConfigurationService $mappingConfigurationService = null;

    public function __construct(
        ?ScimProvisionSettings $provisionSettings = null,
        ?ScimMappingConfigurationService $mappingConfigurationService = null,
    ) {
        $this->provisionSettings = $provisionSettings;
        $this->mappingConfigurationService = $mappingConfigurationService;
    }

    public function getScimBaseUrl(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            return GeneralUtility::makeInstance(ScimRouteResolver::class)->buildPublicBaseUrl($request);
        }

        return rtrim(MoUtilities::getBaseUrl(), '/')
            . ScimRouteResolver::API_BASE_PATH
            . '/';
    }

    public function getBearerToken(): string
    {
        ScimConfig::ensureRow();
        $token = ScimConfig::fetch(Constants::SCIM_BEARER_TOKEN);
        if ($token === null || $token === '') {
            return $this->persistNewBearerToken();
        }

        return $token;
    }

    public function regenerateBearerToken(): string
    {
        $token = $this->persistNewBearerToken();
        MoUtilities::showSuccessFlashMessage('A new SCIM Bearer Token has been generated.');

        return $token;
    }

    /**
     * Persists Azure AD application credentials.
     *
     * Note: The client secret is currently stored but not used by any feature.
     *
     * @param array<mixed> $post Raw $_POST data; keys are not guaranteed to be strings.
     */
    public function saveAzureConfiguration(array $post): void
    {
        ScimConfig::update(Constants::AZURE_TENANT_ID, trim(MoUtilities::stringFromMixed($post['mo_scim_azure_tenant_id'] ?? null)));
        ScimConfig::update(Constants::AZURE_CLIENT_ID, trim(MoUtilities::stringFromMixed($post['mo_scim_azure_client_id'] ?? null)));
        ScimConfig::update(Constants::AZURE_TENANT_DOMAIN, trim(MoUtilities::stringFromMixed($post['mo_scim_azure_tenant_domain'] ?? null)));
        ScimConfig::update(Constants::AZURE_TEST_UPN, trim(MoUtilities::stringFromMixed($post['mo_scim_azure_test_upn'] ?? null)));

        $clientSecret = trim(MoUtilities::stringFromMixed($post['mo_scim_azure_client_secret'] ?? null));
        if ($clientSecret !== '') {
            ScimConfig::update(
                Constants::AZURE_CLIENT_SECRET,
                AESEncryption::encrypt_data($clientSecret)
            );
        }

        MoUtilities::showSuccessFlashMessage('IDP Apps Graph API configuration has been saved.');
    }

    /**
     * @param array<mixed> $post Raw $_POST data; keys are not guaranteed to be strings.
     */
    public function saveSyncConfiguration(array $post): void
    {
        $targets = [];
        if (isset($post['mo_scim_provision_fe'])) {
            $targets[] = 'fe_users';
        }
        if (isset($post['mo_scim_provision_be'])) {
            $targets[] = 'be_users';
        }

        ScimConfig::update(Constants::PROVISION_TARGET, implode(',', $targets));
        ScimConfig::update(Constants::SYNC_CREATE_USERS, isset($post['mo_scim_sync_create']) ? '1' : '0');
        ScimConfig::update(Constants::SYNC_UPDATE_USERS, isset($post['mo_scim_sync_update']) ? '1' : '0');
        ScimConfig::update(Constants::SYNC_DISABLE_USERS, isset($post['mo_scim_sync_disable']) ? '1' : '0');
        ScimConfig::update(
            Constants::DELETE_ON_DEACTIVATION,
            isset($post['mo_scim_delete_on_deactivation']) ? '1' : '0'
        );
        ScimConfig::update(
            Constants::FE_USERS_STORAGE_PID,
            (string)max(0, MoUtilities::intFromMixed($post['mo_scim_fe_users_storage_pid'] ?? null))
        );
        ScimConfig::update(
            Constants::BE_USERS_STORAGE_PID,
            (string)max(0, MoUtilities::intFromMixed($post['mo_scim_be_users_storage_pid'] ?? null))
        );

        MoUtilities::showSuccessFlashMessage('TYPO3 target user sync settings have been saved.');
    }

    /**
     * @param array<mixed> $post Raw $_POST data; keys are not guaranteed to be strings.
     */
    public function saveAttributeMappingConfiguration(array $post): void
    {
        $this->getMappingConfigurationService()->saveAttributeMapping($post);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigurationForView(): array
    {
        ScimConfig::ensureRow();

        $encryptedSecret = ScimConfig::fetch(Constants::AZURE_CLIENT_SECRET);
        $hasClientSecret = $encryptedSecret !== null && $encryptedSecret !== '';

        return [
            'scim_base_url' => $this->getScimBaseUrl(),
            'scim_bearer_token' => $this->getBearerToken(),
            'azure_tenant_id' => ScimConfig::fetch(Constants::AZURE_TENANT_ID) ?? '',
            'azure_client_id' => ScimConfig::fetch(Constants::AZURE_CLIENT_ID) ?? '',
            'azure_client_secret_placeholder' => $hasClientSecret ? '********' : '',
            'azure_tenant_domain' => ScimConfig::fetch(Constants::AZURE_TENANT_DOMAIN) ?? '',
            'azure_test_upn' => ScimConfig::fetch(Constants::AZURE_TEST_UPN) ?? '',
            'provision_target_fe' => $this->getProvisionSettings()->isTargetEnabled('fe_users'),
            'provision_target_be' => $this->getProvisionSettings()->isTargetEnabled('be_users'),
            'sync_create_users' => $this->getProvisionSettings()->isCreateEnabled(),
            'sync_update_users' => $this->getProvisionSettings()->isUpdateEnabled(),
            'sync_disable_users' => $this->getProvisionSettings()->isDisableEnabled(),
            'delete_on_deactivation' => $this->getProvisionSettings()->isDeleteOnDeactivationEnabled(),
            'fe_users_storage_pid' => (string)$this->getProvisionSettings()->getFrontendUsersStoragePid(),
            'be_users_storage_pid' => (string)$this->getProvisionSettings()->getBackendUsersStoragePid(),
            ...$this->getMappingConfigurationService()->getMappingConfigurationForView(),
        ];
    }

    private function getProvisionSettings(): ScimProvisionSettings
    {
        return $this->provisionSettings ??= GeneralUtility::makeInstance(ScimProvisionSettings::class);
    }

    private function getMappingConfigurationService(): ScimMappingConfigurationService
    {
        return $this->mappingConfigurationService ??= GeneralUtility::makeInstance(ScimMappingConfigurationService::class);
    }

    private function persistNewBearerToken(): string
    {
        $token = bin2hex(random_bytes(32));
        ScimConfig::update(Constants::SCIM_BEARER_TOKEN, $token);

        return $token;
    }

}
