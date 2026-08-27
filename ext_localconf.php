<?php

defined('TYPO3') or die();

call_user_func(static function (): void {
    // SCIM provisioning API: /scim/v2/ (preferred) and legacy /index.php?eID=scim_endpoint
    $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
    $typo3ConfVars = is_array($typo3ConfVars) ? $typo3ConfVars : [];

    $GLOBALS['TYPO3_CONF_VARS'] = \TYPO3\CMS\Core\Utility\ArrayUtility::setValueByPath($typo3ConfVars,'FE/eID_include/scim_endpoint',\Miniorange\Scim\Http\ScimEidDispatcher::class . '::dispatch');

    $iconRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Imaging\IconRegistry::class);
    $iconRegistry->registerIcon(
        'scim-plugin-bekey',
        \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        ['source' => 'EXT:scim_user_provisioning/Resources/Public/Icons/miniorange.svg']
    );
});
