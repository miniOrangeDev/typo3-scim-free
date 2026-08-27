<?php

declare(strict_types=1);

namespace Miniorange\Scim\Helper;

class Constants
{
    const HOSTNAME = 'https://login.xecurify.com';

    const TABLE_SCIM_CONFIG = 'miniorange_scim_config';

    const SCIM_BEARER_TOKEN = 'scim_bearer_token';
    const AZURE_TENANT_ID = 'azure_tenant_id';
    const AZURE_CLIENT_ID = 'azure_client_id';
    const AZURE_CLIENT_SECRET = 'azure_client_secret';
    const AZURE_TENANT_DOMAIN = 'azure_tenant_domain';
    const AZURE_TEST_UPN = 'azure_test_upn';
    const PROVISION_TARGET = 'provision_target';
    const SYNC_CREATE_USERS = 'sync_create_users';
    const SYNC_UPDATE_USERS = 'sync_update_users';
    const SYNC_DISABLE_USERS = 'sync_disable_users';
    const DELETE_ON_DEACTIVATION = 'delete_on_deactivation';
    const FE_USERS_STORAGE_PID = 'fe_users_storage_pid';
    const BE_USERS_STORAGE_PID = 'be_users_storage_pid';
    const ATTRIBUTE_MAPPING = 'attribute_mapping';
    const CUSTOMER_KEY = "cust_key";
    const CUSTOMER_API_KEY = "cust_api_key";

    const USERS_LIMIT = 'users_limit';
    const SYNCED_USERS_COUNT = 'synced_users_count';
    const DEFAULT_USERS_LIMIT = 10;
    const AREA_OF_INTEREST = 'TYPO3 SCIM Extension';
    const SUPPORT_QUERY_OPTION = 'mo_scim_contact_us_query_option';
    const PLUGIN_VERSION = 'v1.0.0';
}
