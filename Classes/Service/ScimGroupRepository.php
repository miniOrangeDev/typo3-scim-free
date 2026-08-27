<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service;

use Miniorange\Scim\Helper\MoUtilities;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Isolated group lookups: backend uses be_groups only, frontend uses fe_groups only.
 */
final class ScimGroupRepository
{
    private const TABLE_BE_GROUPS = 'be_groups';
    private const TABLE_FE_GROUPS = 'fe_groups';

    private ?ConnectionPool $connectionPool = null;

    public function __construct(?ConnectionPool $connectionPool = null)
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * @return list<array{uid: int, title: string}>
     */
    public function findBackendGroups(): array
    {
        return $this->findActiveGroups(self::TABLE_BE_GROUPS);
    }

    /**
     * @return list<array{uid: int, title: string}>
     */
    public function findFrontendGroups(): array
    {
        return $this->findActiveGroups(self::TABLE_FE_GROUPS);
    }

    /**
     * @return list<array{uid: int, title: string}>
     */
    private function findActiveGroups(string $table): array
    {
        $connectionPool = $this->getConnectionPool();
        if (!$connectionPool->getConnectionForTable($table)->createSchemaManager()->tablesExist([$table])) {
            return [];
        }

        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $groups = [];
        foreach ($rows as $row) {
            if (!isset($row['uid'])) {
                continue;
            }
            $groups[] = [
                'uid' => MoUtilities::intFromMixed($row['uid']),
                'title' => trim(MoUtilities::stringFromMixed($row['title'] ?? null)),
            ];
        }

        return $groups;
    }

    private function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool ??= GeneralUtility::makeInstance(ConnectionPool::class);
    }
}
