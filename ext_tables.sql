#
# SCIM endpoint credentials and Azure / sync settings
#
CREATE TABLE miniorange_scim_config
(
    id                   int(11) NOT NULL auto_increment,
    scim_bearer_token    varchar(255) DEFAULT '',
    azure_tenant_id      varchar(255) DEFAULT '',
    azure_client_id      varchar(255) DEFAULT '',
    azure_client_secret  text         DEFAULT '',
    azure_tenant_domain  varchar(255) DEFAULT '',
    azure_test_upn       varchar(255) DEFAULT '',
    provision_target     varchar(32)  DEFAULT '',
    sync_create_users    tinyint(1)   DEFAULT 0,
    sync_update_users    tinyint(1)   DEFAULT 0,
    sync_disable_users   tinyint(1)   DEFAULT 0,
    delete_on_deactivation tinyint(1) DEFAULT 0,
    fe_users_storage_pid int(11)      DEFAULT 0,
    be_users_storage_pid int(11)      DEFAULT 0,
    attribute_mapping    text         DEFAULT '',
    users_limit          int(11)      DEFAULT 10 NOT NULL,
    synced_users_count   int(11)      DEFAULT 0  NOT NULL,
    PRIMARY KEY (id)
);

#
# Persist the SCIM externalId on provisioned user records to support
# user lookup during SCIM deprovisioning.
#
# tx_scim_managed identifies users created by this extension and is used
# to restrict SCIM read, update, patch, and delete operations.
#
# Username uniqueness is enforced by the application layer
# (see ScimUserSyncService), not the database schema.
#
CREATE TABLE fe_users
(
    tx_scim_external_id varchar(255) DEFAULT '' NOT NULL,
    tx_scim_managed      tinyint(1)  DEFAULT 0  NOT NULL,

    KEY tx_scim_external_id (tx_scim_external_id)
);

CREATE TABLE be_users
(
    tx_scim_external_id varchar(255) DEFAULT '' NOT NULL,
    tx_scim_managed      tinyint(1)  DEFAULT 0  NOT NULL,

    KEY tx_scim_external_id (tx_scim_external_id)
);
