<?php

$_EXTKEY = 'scim_user_provisioning';

/** @var array<string, mixed> $EM_CONF */
$EM_CONF[$_EXTKEY] = [
    'title' => 'SCIM User Provisioning and Sync',
    'description' => 'TYPO3 SCIM User Provisioning and Sync extension by miniOrange enables automated user provisioning and synchronization between TYPO3 and SCIM-compliant Identity Providers such as Microsoft Entra ID (Azure AD), Okta, OneLogin, Google Workspace, Keycloak, JumpCloud, Centrify, miniOrange, and other custom SCIM providers. The TYPO3 SCIM extension automates user lifecycle management by allowing users to be created, updated, synchronized, and deprovisioned in TYPO3 based on changes made in the connected Identity Provider. It provides features such as Real-Time User Provisioning, Automated User Deprovisioning, Attribute Mapping, Custom Attribute Mapping, and Group Mapping to keep TYPO3 user information synchronized with your Identity Provider. The extension makes TYPO3 a SCIM-compliant endpoint, allowing organizations to centrally manage users and reduce manual user management while maintaining consistent identity information across connected applications and systems.',
    'category' => 'services',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-13.4.99',
        ],
    ],
    'version' => '1.0.0',
    'state' => 'stable',
    'author' => 'miniOrange',
    'autoload' => [
        'psr-4' => [
            'Miniorange\\Scim\\' => 'Classes/',
        ],
    ],
];
