<?php

use Miniorange\Scim\Controller\ScimController;

return [
    'tools_scim' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_extensionmanager'],
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'scim-plugin-bekey',
        'path' => '/module/tools/scim',
        'labels' => 'LLL:EXT:scim_user_provisioning/Resources/Private/Language/locallang_bekey.xlf',
        'extensionName' => 'scim_user_provisioning',
        'controllerActions' => [
            ScimController::class => [
                'request',
                'scimConfiguration',
                'syncConfiguration',
                'attributeMapping',
                'roleMapping',
            ],
        ],
    ],
];
