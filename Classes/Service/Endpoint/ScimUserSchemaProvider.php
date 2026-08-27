<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

/**
 * RFC 7643 §4.1 attribute metadata for the SCIM core User schema, limited to the attributes
 * this extension actually reads/maps (userName, name, emails, active, externalId) so an IdP's
 * schema-driven attribute-mapping UI has something real to introspect against. Kept as static
 * data separate from ScimRequestHandler so schema metadata can be reviewed/changed without
 * touching request-dispatch logic.
 */
final class ScimUserSchemaProvider
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function coreUserAttributes(): array
    {
        return [
            [
                'name' => 'userName',
                'type' => 'string',
                'multiValued' => false,
                'description' => 'Unique identifier for the user, used to authenticate to the service provider.',
                'required' => true,
                'caseExact' => false,
                'mutability' => 'readWrite',
                'returned' => 'default',
                'uniqueness' => 'server',
            ],
            [
                'name' => 'name',
                'type' => 'complex',
                'multiValued' => false,
                'description' => "The components of the user's real name.",
                'required' => false,
                'mutability' => 'readWrite',
                'returned' => 'default',
                'subAttributes' => [
                    [
                        'name' => 'givenName',
                        'type' => 'string',
                        'multiValued' => false,
                        'description' => 'The given name of the user.',
                        'required' => false,
                        'caseExact' => false,
                        'mutability' => 'readWrite',
                        'returned' => 'default',
                        'uniqueness' => 'none',
                    ],
                    [
                        'name' => 'familyName',
                        'type' => 'string',
                        'multiValued' => false,
                        'description' => 'The family name of the user.',
                        'required' => false,
                        'caseExact' => false,
                        'mutability' => 'readWrite',
                        'returned' => 'default',
                        'uniqueness' => 'none',
                    ],
                ],
            ],
            [
                'name' => 'emails',
                'type' => 'complex',
                'multiValued' => true,
                'description' => 'Email addresses for the user.',
                'required' => false,
                'mutability' => 'readWrite',
                'returned' => 'default',
                'subAttributes' => [
                    [
                        'name' => 'value',
                        'type' => 'string',
                        'multiValued' => false,
                        'description' => 'Email address.',
                        'required' => false,
                        'caseExact' => false,
                        'mutability' => 'readWrite',
                        'returned' => 'default',
                        'uniqueness' => 'none',
                    ],
                    [
                        'name' => 'type',
                        'type' => 'string',
                        'multiValued' => false,
                        'description' => 'A label indicating the attribute\'s function, e.g. "work" or "home".',
                        'required' => false,
                        'caseExact' => false,
                        'canonicalValues' => ['work', 'home', 'other'],
                        'mutability' => 'readWrite',
                        'returned' => 'default',
                        'uniqueness' => 'none',
                    ],
                    [
                        'name' => 'primary',
                        'type' => 'boolean',
                        'multiValued' => false,
                        'description' => 'Indicates the primary email address.',
                        'required' => false,
                        'mutability' => 'readWrite',
                        'returned' => 'default',
                    ],
                ],
            ],
            [
                'name' => 'active',
                'type' => 'boolean',
                'multiValued' => false,
                'description' => 'Indicates whether the user is active in the service provider.',
                'required' => false,
                'mutability' => 'readWrite',
                'returned' => 'default',
            ],
            [
                'name' => 'externalId',
                'type' => 'string',
                'multiValued' => false,
                'description' => "An identifier for the user as defined by the provisioning client (e.g. the IdP's object id), used to correlate the resource across create/update/delete operations.",
                'required' => false,
                'caseExact' => false,
                'mutability' => 'readWrite',
                'returned' => 'default',
                'uniqueness' => 'none',
            ],
        ];
    }
}
