<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service;

use Miniorange\Scim\Helper\Constants;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Helper\ScimConfig;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ScimUserSyncLimitService
{
    private const TABLE_FE_USERS = 'fe_users';
    private const TABLE_BE_USERS = 'be_users';
    private const COLUMN_EXTERNAL_ID = 'tx_scim_external_id';

    private ?ConnectionPool $connectionPool = null;

    /**
     * @var array<string, bool>
     */
    private array $externalIdColumnSupport = [];

    public function __construct(?ConnectionPool $connectionPool = null)
    {
        $this->connectionPool = $connectionPool;
    }

    public function getUsersLimit(): int
    {
        return $this->resolveStoredLimit(
            ScimConfig::fetch(Constants::USERS_LIMIT),
            Constants::DEFAULT_USERS_LIMIT
        );
    }

    /**
     * Stored counter from miniorange_scim_config. Incremented when SCIM creates a new user.
     */
    public function getSyncedUsersCount(): int
    {
        return $this->resolveStoredLimit(
            ScimConfig::fetch(Constants::SYNCED_USERS_COUNT),
            0
        );
    }

    /**
     * Actual SCIM-provisioned users in fe_users + be_users (non-empty tx_scim_external_id).
     * Includes disabled and soft-deleted rows so deprovisioning cannot free a license slot.
     */
    public function getDatabaseProvisionedUsersCount(): int
    {
        return $this->countProvisionedUsersInTable(self::TABLE_FE_USERS)
            + $this->countProvisionedUsersInTable(self::TABLE_BE_USERS);
    }

    /**
     * License-relevant count: never lower than the stored synced_users_count.
     */
    public function getEffectiveProvisionedUsersCount(): int
    {
        return max(
            $this->getDatabaseProvisionedUsersCount(),
            $this->getSyncedUsersCount()
        );
    }

    public function isLimitExceeded(): bool
    {
        return $this->getEffectiveProvisionedUsersCount() >= $this->getUsersLimit();
    }

    public function canProvisionNewUser(): bool
    {
        return !$this->isLimitExceeded();
    }

    /**
     * Atomically increments the counter in a single SQL statement to
     * prevent lost updates during concurrent requests.
     */
    public function incrementSyncedUsersCount(): void
    {
        ScimConfig::increment(Constants::SYNCED_USERS_COUNT, 1);
    }

    /**
     * @param array<string, mixed> $syncResults
     */
    public function recordSuccessfulProvisioning(array $syncResults): void
    {
        if (!$this->provisioningCreatedNewUser($syncResults)) {
            return;
        }

        $this->incrementSyncedUsersCount();
    }

    /**
     * Counts rows with a non-empty SCIM externalId. Returns 0 when the column is not
     * present yet (database compare not applied), so limit checks remain safe.
     */
    private function countProvisionedUsersInTable(string $table): int
    {
        if (!$this->tableSupportsExternalIdColumn($table)) {
            return 0;
        }

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        // Include disabled and soft-deleted users — they still consume a license slot.
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->isNotNull(self::COLUMN_EXTERNAL_ID),
                $queryBuilder->expr()->neq(
                    self::COLUMN_EXTERNAL_ID,
                    $queryBuilder->createNamedParameter('', Connection::PARAM_STR)
                )
            )
            ->executeQuery()
            ->fetchOne();

        return MoUtilities::intFromMixed($count);
    }

    private function tableSupportsExternalIdColumn(string $table): bool
    {
        if (array_key_exists($table, $this->externalIdColumnSupport)) {
            return $this->externalIdColumnSupport[$table];
        }

        $supported = false;
        try {
            $schemaManager = $this->getConnectionPool()
                ->getConnectionForTable($table)
                ->createSchemaManager();
            foreach ($schemaManager->listTableColumns($table) as $tableColumn) {
                if (strtolower($tableColumn->getName()) === self::COLUMN_EXTERNAL_ID) {
                    $supported = true;
                    break;
                }
            }
        } catch (\Throwable) {
            $supported = false;
        }

        $this->externalIdColumnSupport[$table] = $supported;

        return $supported;
    }

    /**
     * @param array<string, mixed> $syncResults
     */
    private function provisioningCreatedNewUser(array $syncResults): bool
    {
        foreach ($syncResults as $scopeResult) {
            if (!is_array($scopeResult)) {
                continue;
            }
            if (($scopeResult['created'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function resolveStoredLimit(?string $stored, int $default): int
    {
        if ($stored === null || $stored === '' || !is_numeric($stored)) {
            return $default;
        }

        return max(0, (int)$stored);
    }

    private function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool ??= GeneralUtility::makeInstance(ConnectionPool::class);
    }
}
