<?php

declare(strict_types=1);

namespace Miniorange\Scim\Helper;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ScimConfig
{
    private const CACHE_KEY_TABLE_EXISTS = 'scim_config_table_exists';
    private const CACHE_KEY_ROW = 'scim_config_row';

    public static function tableExists(): bool
    {
        $cache = self::getRuntimeCache();
        if ($cache->has(self::CACHE_KEY_TABLE_EXISTS)) {
            return (bool)$cache->get(self::CACHE_KEY_TABLE_EXISTS);
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

        $exists = $connection->createSchemaManager()->tablesExist([Constants::TABLE_SCIM_CONFIG]);
        $cache->set(self::CACHE_KEY_TABLE_EXISTS, $exists);

        return $exists;
    }

    public static function ensureRow(): void
    {
        if (!self::tableExists()) {
            return;
        }
        if (self::fetch('id') === null) {
            self::insertRow();
        }
    }

    public static function fetch(string $column): ?string
    {
        if (!self::tableExists()) {
            return null;
        }

        $row = self::loadRow();
        if (!is_array($row) || !array_key_exists($column, $row) || $row[$column] === null) {
            return null;
        }

        return MoUtilities::stringFromMixed($row[$column]);
    }

    /**
     * Loads and caches the config row for the current request using TYPO3's
     * runtime cache to avoid repeated database queries.
     *
     * @return array<string, mixed>|false
     */
    private static function loadRow(): array|false
    {
        $cache = self::getRuntimeCache();
        if ($cache->has(self::CACHE_KEY_ROW)) {
            $cached = $cache->get(self::CACHE_KEY_ROW);
            if (!is_array($cached)) {
                return false;
            }

            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(Constants::TABLE_SCIM_CONFIG);

        $row = $queryBuilder
            ->select('*')
            ->from(Constants::TABLE_SCIM_CONFIG)
            ->where(
                $queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        $cache->set(self::CACHE_KEY_ROW, $row);

        return $row;
    }

    private static function invalidateRowCache(): void
    {
        self::getRuntimeCache()->remove(self::CACHE_KEY_ROW);
    }

    private static function getRuntimeCache(): FrontendInterface
    {
        return GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
    }

    /**
     * @return array<string, mixed>
     */
    public static function fetchJson(string $column): array
    {
        $raw = self::fetch($column);
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function updateJson(string $column, array $value): void
    {
        self::update(
            $column,
            (string)json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function update(string $column, ?string $value): void
    {
        if (!self::tableExists()) {
            return;
        }

        self::ensureRow();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(Constants::TABLE_SCIM_CONFIG);

        $query = $queryBuilder
            ->update(Constants::TABLE_SCIM_CONFIG)
            ->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->set($column, $value);

        $query->executeStatement();

        self::invalidateRowCache();
    }

    /**
     * Atomically increments a numeric column in a single SQL update to
     * prevent race conditions during concurrent requests.
     */
    public static function increment(string $column, int $amount): void
    {
        if (!self::tableExists()) {
            return;
        }

        self::ensureRow();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(Constants::TABLE_SCIM_CONFIG);

        $queryBuilder
            ->update(Constants::TABLE_SCIM_CONFIG)
            ->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->set($column, $queryBuilder->quoteIdentifier($column) . ' + ' . (int)$amount, false)
            ->executeStatement();

        self::invalidateRowCache();
    }

    private static function insertRow(): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(Constants::TABLE_SCIM_CONFIG);

        $query = $queryBuilder
            ->insert(Constants::TABLE_SCIM_CONFIG)
            ->values([
                'id' => 1,
                'provision_target' => '',
                'sync_create_users' => 0,
                'sync_update_users' => 0,
                'sync_disable_users' => 0,
                'delete_on_deactivation' => 0,
                'fe_users_storage_pid' => 0,
                'be_users_storage_pid' => 0,
                'attribute_mapping' => '',
                'users_limit' => Constants::DEFAULT_USERS_LIMIT,
                'synced_users_count' => 0,
            ]);

        $query->executeStatement();

        // Clear the cached missing-row state after inserting the row.
        self::invalidateRowCache();
    }
}
