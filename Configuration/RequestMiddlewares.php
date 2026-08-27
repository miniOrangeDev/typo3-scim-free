<?php

declare(strict_types=1);

use Miniorange\Scim\Middleware\ScimApiMiddleware;

/**
 * Registers the SCIM REST API middleware on the TYPO3 frontend stack.
 */
return [
    'frontend' => [
        'miniorange/scim/scim-api' => [
            'target' => ScimApiMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
        ],
    ],
];
