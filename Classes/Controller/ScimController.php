<?php

declare(strict_types=1);

namespace Miniorange\Scim\Controller;

use Miniorange\Scim\Helper\Constants;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Service\ScimConfigurationService;
use Miniorange\Scim\Service\ScimGroupRepository;
use Miniorange\Scim\Service\ScimUserSyncLimitService;
use Miniorange\Scim\Service\SupportService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\FormProtection\AbstractFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend module: SCIM configuration and support.
 */
class ScimController extends ActionController
{
    private const FORM_PROTECTION_NAME = 'tx_scim_scim_module';

    /**
     * POST options that mutate persisted configuration or submit data to an external API;
     * these require a valid form-protection token to guard against CSRF.
     */
    private const STATE_CHANGING_OPTIONS = [
        Constants::SUPPORT_QUERY_OPTION,
        ScimConfigurationService::OPTION_REGENERATE_TOKEN,
        ScimConfigurationService::OPTION_SAVE_AZURE,
        ScimConfigurationService::OPTION_SAVE_SYNC,
        ScimConfigurationService::OPTION_SAVE_ATTRIBUTE_MAPPING,
    ];

    protected string $tab = '';

    private ?PageRenderer $pageRenderer = null;
    private ?SupportService $supportService = null;
    private ?ScimConfigurationService $scimConfigurationService = null;

    public function __construct(
        ?PageRenderer $pageRenderer = null,
        ?SupportService $supportService = null,
        ?ScimConfigurationService $scimConfigurationService = null,
    ) {
        $this->pageRenderer = $pageRenderer;
        $this->supportService = $supportService;
        $this->scimConfigurationService = $scimConfigurationService;
    }

    public function requestAction(): ResponseInterface
    {
        if ($this->tab === '') {
            $this->tab = 'Scim_Configuration';
        }

        return $this->renderModuleRequest();
    }

    public function scimConfigurationAction(): ResponseInterface
    {
        $this->tab = 'Scim_Configuration';

        return $this->renderModuleRequest();
    }

    public function syncConfigurationAction(): ResponseInterface
    {
        $this->tab = 'Sync_Configuration';

        return $this->renderModuleRequest();
    }

    public function attributeMappingAction(): ResponseInterface
    {
        $this->tab = 'Attribute_Mapping';

        return $this->renderModuleRequest();
    }

    public function roleMappingAction(): ResponseInterface
    {
        $this->tab = 'Role_Mapping';

        return $this->renderModuleRequest();
    }

    private function renderModuleRequest(): ResponseInterface
    {
        $this->loadAssets();
        $this->handlePost();

        $this->view->assign('tab', $this->tab);
        $this->view->assign('formToken', $this->generateFormToken());
        $this->view->assignMultiple($this->getScimConfigurationService()->getConfigurationForView());
        $this->assignRoleMappingViewData();
        $this->assignUserSyncLimitViewData();
        $this->assignUpgradeTabToView();
        $this->assignSupportEmailToView();

        return $this->htmlResponse();
    }

    private function loadAssets(): void
    {
        $pageRenderer = $this->getPageRenderer();
        $pageRenderer->addCssFile('@miniorange/scim/Resources/Public/Css/oauth/bootstrap.min.css');
        $pageRenderer->addCssFile('@miniorange/scim/Resources/Public/Css/oauth/font-awesome.min.css');
        $pageRenderer->addCssFile('@miniorange/scim/Resources/Public/Css/oauth/main.css');
        $pageRenderer->addCssFile('@miniorange/scim/Resources/Public/Css/oauth/upgrade.css');
        $pageRenderer->loadJavaScriptModule('@miniorange/scim/jquery.min.js');
        $pageRenderer->loadJavaScriptModule('@miniorange/scim/bootstrap.min.js');
        $pageRenderer->loadJavaScriptModule('@miniorange/scim/main.js');
    }

    private function handlePost(): void
    {
        $postData = $this->getPostData();
        if (!isset($postData['option'])) {
            return;
        }

        $option = MoUtilities::stringFromMixed($postData['option']);

        if (in_array($option, self::STATE_CHANGING_OPTIONS, true) && !$this->hasValidFormToken($postData)) {
            MoUtilities::showErrorFlashMessage('Your session has expired or the request could not be verified. Please try again.');

            return;
        }

        if ($this->isUserSyncLimitExceeded()
            && in_array($option, [
                ScimConfigurationService::OPTION_REGENERATE_TOKEN,
                ScimConfigurationService::OPTION_SAVE_SYNC,
            ], true)
        ) {
            return;
        }

        if ($option === Constants::SUPPORT_QUERY_OPTION) {
            $this->getSupportService()->submitSupportQuery($postData);
            $returnTab = trim(MoUtilities::stringFromMixed($postData['return_tab'] ?? null));
            if ($returnTab !== '') {
                $this->tab = $returnTab;
            }
        } elseif ($option === ScimConfigurationService::OPTION_REGENERATE_TOKEN) {
            $this->getScimConfigurationService()->regenerateBearerToken();
            $this->tab = 'Scim_Configuration';
        } elseif ($option === ScimConfigurationService::OPTION_SAVE_AZURE) {
            $this->getScimConfigurationService()->saveAzureConfiguration($postData);
            $this->tab = 'Sync_Configuration';
        } elseif ($option === ScimConfigurationService::OPTION_SAVE_SYNC) {
            $this->getScimConfigurationService()->saveSyncConfiguration($postData);
            $this->tab = 'Sync_Configuration';
        } elseif ($option === ScimConfigurationService::OPTION_SAVE_ATTRIBUTE_MAPPING) {
            $this->getScimConfigurationService()->saveAttributeMappingConfiguration($postData);
            $this->tab = 'Attribute_Mapping';
        }

        if ($option === 'Upgrade_Manager') {
            $this->tab = 'Upgrade_Manager';
        } elseif ($option === 'Scim_Configuration') {
            $this->tab = 'Scim_Configuration';
        } elseif ($option === 'Sync_Configuration') {
            $this->tab = 'Sync_Configuration';
        } elseif ($option === 'Attribute_Mapping') {
            $this->tab = 'Attribute_Mapping';
        } elseif ($option === 'Role_Mapping') {
            $this->tab = 'Role_Mapping';
        }
    }

    private function assignRoleMappingViewData(): void
    {
        $groupRepository = GeneralUtility::makeInstance(ScimGroupRepository::class);

        $this->view->assignMultiple([
            'update_backend_roles' => false,
            'update_frontend_roles' => false,
            'backend_default_group_uid' => 0,
            'frontend_default_group_uid' => 0,
            'be_groups' => $this->enrichGroupsForRoleMappingDisplay($groupRepository->findBackendGroups()),
            'fe_groups' => $this->enrichGroupsForRoleMappingDisplay($groupRepository->findFrontendGroups()),
        ]);
    }

    /**
     * @param list<array{uid: int, title: string}> $groups
     * @return list<array{uid: int, title: string, role_mapping_tokens: string}>
     */
    private function enrichGroupsForRoleMappingDisplay(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['role_mapping_tokens'] = '';
        }
        unset($group);

        return $groups;
    }

    private function assignUserSyncLimitViewData(): void
    {
        $limitService = GeneralUtility::makeInstance(ScimUserSyncLimitService::class);

        $this->view->assignMultiple([
            'users_limit' => $limitService->getUsersLimit(),
            'synced_users_count' => $limitService->getEffectiveProvisionedUsersCount(),
            'user_sync_limit_exceeded' => $limitService->isLimitExceeded(),
        ]);
    }

    private function isUserSyncLimitExceeded(): bool
    {
        return GeneralUtility::makeInstance(ScimUserSyncLimitService::class)->isLimitExceeded();
    }

    private function assignUpgradeTabToView(): void
    {
        $this->view->assignMultiple([
            'planName' => 'SCIM',
            'planVersion' => Constants::PLUGIN_VERSION,
        ]);
    }

    private function assignSupportEmailToView(): void
    {
        $beUser = $this->request->getAttribute('backend.user');
        $userRecord = $beUser instanceof BackendUserAuthentication ? $beUser->user : null;
        $userRecord = is_array($userRecord) ? $userRecord : [];

        $email = trim(MoUtilities::stringFromMixed($userRecord['email'] ?? null));
        if ($email === '') {
            $email = trim(MoUtilities::stringFromMixed($userRecord['username'] ?? null));
        }

        $this->view->assign('email', $email);
    }

    private function generateFormToken(): string
    {
        return $this->getFormProtection()->generateToken(self::FORM_PROTECTION_NAME);
    }

    /**
     * @param array<string, mixed> $postData
     */
    private function hasValidFormToken(array $postData): bool
    {
        $token = trim(MoUtilities::stringFromMixed($postData['mo_scim_form_token'] ?? null));

        return $token !== '' && $this->getFormProtection()->validateToken($token, self::FORM_PROTECTION_NAME);
    }

    /**
     * Reads POST data from the PSR-7 request instead of the $_POST superglobal directly,
     * so this controller stays testable and consistent with the rest of the request lifecycle.
     *
     * @return array<string, mixed>
     */
    private function getPostData(): array
    {
        $parsedBody = $this->request->getParsedBody();
        if (!is_array($parsedBody)) {
            return [];
        }

        $postData = [];
        foreach ($parsedBody as $key => $value) {
            $postData[(string)$key] = $value;
        }

        return $postData;
    }

    private function getFormProtection(): AbstractFormProtection
    {
        return GeneralUtility::makeInstance(FormProtectionFactory::class)->createForType('backend');
    }

    private function getPageRenderer(): PageRenderer
    {
        return $this->pageRenderer ??= GeneralUtility::makeInstance(PageRenderer::class);
    }

    private function getSupportService(): SupportService
    {
        return $this->supportService ??= GeneralUtility::makeInstance(SupportService::class);
    }

    private function getScimConfigurationService(): ScimConfigurationService
    {
        return $this->scimConfigurationService ??= GeneralUtility::makeInstance(ScimConfigurationService::class);
    }
}
