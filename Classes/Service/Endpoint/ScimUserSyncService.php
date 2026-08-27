<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Miniorange\Scim\Dto\ScimUserDto;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Service\ScimMappingConfigurationService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ScimUserSyncService
{
    private const TABLE_FE_USERS = 'fe_users';
    private const TABLE_BE_USERS = 'be_users';
    private const SCOPE_FE_USERS = 'fe_users';
    private const SCOPE_BE_USERS = 'be_users';
    private const PATCH_OP_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const MAX_PATCH_OPERATIONS = 100;
    private const USER_LIFECYCLE_COLUMN_DELETED = 'deleted';
    private const USER_LIFECYCLE_COLUMN_DISABLED = 'disable';
    private const USER_COLUMN_EXTERNAL_ID = 'tx_scim_external_id';

    /**
     * Marks records managed by this extension. All SCIM operations must
     * be scoped to this flag.
     */
    private const USER_COLUMN_SCIM_MANAGED = 'tx_scim_managed';

    private ?ConnectionPool $connectionPool = null;
    private ?ScimProvisionSettings $provisionSettings = null;
    private ?ScimMappingConfigurationService $mappingConfigurationService = null;
    private ?ScimAttributeMapper $attributeMapper = null;
    private ?PasswordHashFactory $passwordHashFactory = null;
    private ?LoggerInterface $logger = null;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?ScimProvisionSettings $provisionSettings = null,
        ?ScimMappingConfigurationService $mappingConfigurationService = null,
        ?ScimAttributeMapper $attributeMapper = null,
        ?PasswordHashFactory $passwordHashFactory = null,
    ) {
        $this->connectionPool = $connectionPool;
        $this->provisionSettings = $provisionSettings;
        $this->mappingConfigurationService = $mappingConfigurationService;
        $this->attributeMapper = $attributeMapper;
        $this->passwordHashFactory = $passwordHashFactory;
    }

    /**
     * @return array<string, mixed>
     */
    public function createUser(ScimUserDto $user): array
    {
        $targets = $this->getProvisionSettings()->getEnabledTargets();

        if (count($targets) <= 1) {
            return $this->createUserForTargets($user, $targets);
        }

        // Use a transaction to keep writes across multiple targets atomic.
        return $this->runInTransaction(fn (): array => $this->createUserForTargets($user, $targets));
    }

    /**
     * @return array<string, mixed>
     */
    public function updateUser(ScimUserDto $user, ?string $resourceId = null): array
    {
        $targets = $this->getProvisionSettings()->getEnabledTargets();

        if (count($targets) <= 1) {
            return $this->updateUserForTargets($user, $resourceId, $targets);
        }

        return $this->runInTransaction(fn (): array => $this->updateUserForTargets($user, $resourceId, $targets));
    }

    /**
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    private function createUserForTargets(ScimUserDto $user, array $targets): array
    {
        $results = [];

        if (in_array(self::SCOPE_FE_USERS, $targets, true)) {
            $results[self::SCOPE_FE_USERS] = $this->createFrontendUser($user);
        }
        if (in_array(self::SCOPE_BE_USERS, $targets, true)) {
            $results[self::SCOPE_BE_USERS] = $this->createBackendUser($user);
        }

        return $results;
    }

    /**
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    private function updateUserForTargets(ScimUserDto $user, ?string $resourceId, array $targets): array
    {
        $results = [];

        if (in_array(self::SCOPE_FE_USERS, $targets, true)) {
            $results[self::SCOPE_FE_USERS] = $this->updateFrontendUser($user, $resourceId);
        }
        if (in_array(self::SCOPE_BE_USERS, $targets, true)) {
            $results[self::SCOPE_BE_USERS] = $this->updateBackendUser($user, $resourceId);
        }

        return $results;
    }

    /**
     * Runs $operation inside a transaction on the fe_users/be_users connection, rolling back
     * on any failure. In the common single-connection TYPO3 setup, fe_users and be_users share
     * one Connection instance, so this covers both target writes atomically; if an install maps
     * them to genuinely separate connections, only the fe_users connection's statements are
     * covered — a documented limitation rather than a silent one.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function runInTransaction(callable $operation): mixed
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE_FE_USERS);
        $connection->beginTransaction();

        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            try {
                $connection->rollBack();
            } catch (\Throwable $rollbackException) {
                $this->getLogger()->error(
                    'Rollback failed after a SCIM multi-target sync error: {message}',
                    ['message' => $rollbackException->getMessage()]
                );
            }

            throw $exception;
        }
    }

    public function userExistsInAnyTarget(ScimUserDto $user): bool
    {
        return $this->findExistingUserRecordInAnyTarget($user) !== null;
    }

    /**
     * Returns the uid of an active (non-disabled, non-deleted) user for SCIM conflict detection.
     */
    public function findExistingUserUidInAnyTarget(ScimUserDto $user): ?int
    {
        $record = $this->findExistingUserRecordInAnyTarget($user);
        if ($record !== null && $this->isActiveUserRecord($record)) {
            return (int)$record['uid'];
        }

        return null;
    }

    /**
     * Locates a fe_users / be_users row by userName, email, or externalId regardless of the
     * disable or deleted flags so re-provision syncs can update the original record instead of
     * inserting a duplicate.
     *
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}|null
     */
    public function findExistingUserRecordInAnyTarget(ScimUserDto $user): ?array
    {
        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_FE_USERS)) {
            $record = $this->findExistingUserRecordForTable(self::TABLE_FE_USERS, $user);
            if ($record !== null) {
                return $record;
            }
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_BE_USERS)) {
            return $this->findExistingUserRecordForTable(self::TABLE_BE_USERS, $user);
        }

        return null;
    }

    /**
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}|null
     */
    public function findUserRecordByFilterAttribute(string $attribute, string $value): ?array
    {
        $identityValue = trim($value);
        if ($identityValue === '') {
            return null;
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_FE_USERS)) {
            $record = $this->findUserRecordByIdentityValue(self::TABLE_FE_USERS, $identityValue);
            if ($record !== null) {
                return $record;
            }
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_BE_USERS)) {
            return $this->findUserRecordByIdentityValue(self::TABLE_BE_USERS, $identityValue);
        }

        return null;
    }

    /**
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}|null
     */
    public function findUserRecordByUid(string $resourceId): ?array
    {
        if (!ctype_digit($resourceId)) {
            return null;
        }

        $uid = (int)$resourceId;
        if ($uid <= 0) {
            return null;
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_FE_USERS)) {
            $record = $this->findUserRecordByUidInTable(self::TABLE_FE_USERS, $uid);
            if ($record !== null) {
                return $record;
            }
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_BE_USERS)) {
            return $this->findUserRecordByUidInTable(self::TABLE_BE_USERS, $uid);
        }

        return null;
    }

    /**
     * Total number of SCIM-managed rows in $table (used to build ListResponse pagination).
     * $table is expected to be one of the enabled provisioning target scope strings, which are
     * identical to the table name constants in this class ('fe_users'/'be_users').
     */
    public function countScimManagedUserRecords(string $table): int
    {
        if (!$this->tableSupportsScimManagedColumn($table)) {
            return 0;
        }

        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions($table);
        $constraints = $this->buildScimOwnershipConstraints($queryBuilder, $table);

        $count = $queryBuilder
            ->count('uid')
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchOne();

        return MoUtilities::intFromMixed($count);
    }

    /**
     * A page of SCIM-managed rows from $table, ordered by uid for stable pagination across
     * requests. $table is expected to be one of the enabled provisioning target scope strings.
     *
     * @return list<array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}>
     */
    public function findScimManagedUserRecords(string $table, int $limit, int $offset): array
    {
        if (!$this->tableSupportsScimManagedColumn($table) || $limit <= 0) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions($table);
        $constraints = $this->buildScimOwnershipConstraints($queryBuilder, $table);

        $selectFields = $table === self::TABLE_FE_USERS
            ? ['uid', 'username', 'email', 'first_name', 'last_name', 'disable', 'deleted']
            : ['uid', 'username', 'email', 'realName', 'disable', 'deleted'];

        $rows = $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->where(...$constraints)
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult(max(0, $offset))
            ->executeQuery()
            ->fetchAllAssociative();

        $records = [];
        foreach ($rows as $row) {
            $record = $this->normalizeUserRecord($table, $row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int} $record
     * @return array<string, mixed>
     */
    public function formatUserAsScimResource(array $record): array
    {
        $resource = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id' => (string)$record['uid'],
            'userName' => $record['username'],
            'active' => $this->resolveScimActiveFromUserRecord($record),
            'meta' => ['resourceType' => 'User'],
        ];

        $firstName = trim($record['first_name']);
        $lastName = trim($record['last_name']);
        if ($firstName !== '' || $lastName !== '') {
            $resource['name'] = [
                'givenName' => $firstName,
                'familyName' => $lastName,
            ];
        }

        $email = trim($record['email']);
        if ($email !== '') {
            $resource['emails'] = [
                [
                    'value' => $email,
                    'primary' => true,
                ],
            ];
        }

        return $resource;
    }

    /**
     * @param array<string, mixed> $syncResults
     * @return array<string, mixed>|null
     */
    public function buildScimUserResponseFromSyncResults(array $syncResults): ?array
    {
        foreach ($syncResults as $scopeResult) {
            if (!is_array($scopeResult) || !isset($scopeResult['uid'])) {
                continue;
            }

            $record = $this->findUserRecordByUid(MoUtilities::stringFromMixed($scopeResult['uid']));
            if ($record === null) {
                continue;
            }

            $payload = $this->formatUserAsScimResource($record);
            $payload['syncResults'] = $syncResults;

            return $payload;
        }

        return null;
    }

    /**
     * Maps the TYPO3 lifecycle disable/deleted flags to the SCIM core User `active` attribute.
     * A soft-deleted record must report active:false the same as a disabled one — otherwise a
     * direct-uid GET on a deleted user misreports it as active.
     *
     * @param array<string, mixed> $record
     */
    public function resolveScimActiveFromUserRecord(array $record): bool
    {
        return $this->isActiveUserRecord($record);
    }

    /**
     * Applies SCIM PatchOp operations using admin-configured attribute mappings.
     *
     * @param array<string, mixed> $patchPayload
     * @return array{
     *     syncResults: array<string, mixed>,
     *     record: array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}|null,
     *     deleted: bool
     * }
     */
    public function applyPatchOperations(string $resourceId, array $patchPayload): array
    {
        if (!$this->isPatchOpPayload($patchPayload)) {
            throw new \InvalidArgumentException('Payload is not a valid SCIM PatchOp document.');
        }

        $operations = $patchPayload['Operations'] ?? null;
        if (!is_array($operations)) {
            throw new \InvalidArgumentException('PATCH request must include an Operations array.');
        }

        if (count($operations) > self::MAX_PATCH_OPERATIONS) {
            throw new \InvalidArgumentException(
                sprintf('PATCH requests are limited to %d operations per request.', self::MAX_PATCH_OPERATIONS)
            );
        }

        $normalizedOperations = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $normalizedOperation = [];
            foreach ($operation as $key => $value) {
                $normalizedOperation[(string)$key] = $value;
            }

            $normalizedOperations[] = $normalizedOperation;
        }

        $operations = $normalizedOperations;

        $context = $this->resolveUserContextByResourceId($resourceId);
        if ($context === null) {
            throw new \RuntimeException('User was not found for patch.');
        }

        $patchPlan = $this->compilePatchColumnUpdates(
            $context['table'],
            $context['scope'],
            $context['record'],
            $operations
        );

        $scope = $context['scope'];
        $uid = (int)$context['record']['uid'];
        $syncResult = ['uid' => $uid, 'updated' => false, 'deleted' => false];

        $this->runInTransaction(function () use ($context, $operations, $patchPlan, $uid, &$syncResult): void {
            if ($patchPlan['updates'] !== []) {
                $this->applyColumnUpdatesToUser($context['table'], $uid, $patchPlan['updates']);
                $syncResult['updated'] = true;
            }

            if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_FE_USERS)
                && $this->getProvisionSettings()->isTargetEnabled(self::SCOPE_BE_USERS)
            ) {
                $otherTable = $context['table'] === self::TABLE_FE_USERS ? self::TABLE_BE_USERS : self::TABLE_FE_USERS;
                $otherRecord = $this->findUserRecordByIdentityValue($otherTable, (string)$context['record']['username']);
                if ($otherRecord === null && trim((string)$context['record']['email']) !== '') {
                    $otherRecord = $this->findUserRecordByIdentityValue($otherTable, (string)$context['record']['email']);
                }
                if ($otherRecord !== null) {
                    $otherPatchPlan = $this->compilePatchColumnUpdates(
                        $otherTable,
                        $otherTable,
                        $otherRecord,
                        $operations
                    );
                    if ($otherPatchPlan['updates'] !== []) {
                        $this->applyColumnUpdatesToUser($otherTable, (int)$otherRecord['uid'], $otherPatchPlan['updates']);
                    }
                    if ($otherPatchPlan['softDelete']) {
                        $this->softDeleteUserInTable($otherTable, (int)$otherRecord['uid']);
                    }
                }
            }

            if ($patchPlan['softDelete']) {
                $this->softDeleteUserInTable($context['table'], $uid);
                $syncResult['deleted'] = true;
                $syncResult['updated'] = true;
            }
        });

        if ($syncResult['deleted']) {
            return [
                'syncResults' => [$scope => $syncResult],
                'record' => null,
                'deleted' => true,
            ];
        }

        $updatedRecord = $this->findUserRecordByUidInTable($context['table'], $uid);
        if ($updatedRecord === null) {
            throw new \RuntimeException('User was not found after patch.');
        }

        return [
            'syncResults' => [$scope => $syncResult],
            'record' => $updatedRecord,
            'deleted' => false,
        ];
    }

    /**
     * Resolves a user context from a SCIM resource id, tolerating both the numeric
     * TYPO3 uid and non-numeric identifiers (userName/email) that some IdPs — notably
     * IDP — send on DELETE when their stored resource id is not the TYPO3 uid.
     * Without this fallback, a non-numeric id resolves to null and the deletion is
     * silently rejected with a 404, leaving the user record untouched.
     *
     * @return array{table: string, scope: string, record: array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}}|null
     */
    public function resolveUserContextByResourceId(string $resourceId): ?array
    {
        $context = $this->resolveUserContextByUid($resourceId);
        if ($context !== null) {
            return $context;
        }

        $identityValue = trim($resourceId);
        if ($identityValue === '') {
            return null;
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_FE_USERS)) {
            $record = $this->findUserRecordByIdentityValue(self::TABLE_FE_USERS, $identityValue);
            if ($record !== null) {
                return [
                    'table' => self::TABLE_FE_USERS,
                    'scope' => self::SCOPE_FE_USERS,
                    'record' => $record,
                ];
            }
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_BE_USERS)) {
            $record = $this->findUserRecordByIdentityValue(self::TABLE_BE_USERS, $identityValue);
            if ($record !== null) {
                return [
                    'table' => self::TABLE_BE_USERS,
                    'scope' => self::SCOPE_BE_USERS,
                    'record' => $record,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function isPatchOpPayload(array $payload): bool
    {
        $schemas = $payload['schemas'] ?? [];
        if (!is_array($schemas)) {
            return false;
        }

        return in_array(self::PATCH_OP_SCHEMA, $schemas, true);
    }

    /**
     * @return array{table: string, scope: string, record: array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}}|null
     */
    public function resolveUserContextByUid(string $resourceId): ?array
    {
        if (!ctype_digit($resourceId)) {
            return null;
        }

        $uid = (int)$resourceId;
        if ($uid <= 0) {
            return null;
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_FE_USERS)) {
            $record = $this->findUserRecordByUidInTable(self::TABLE_FE_USERS, $uid);
            if ($record !== null) {
                return [
                    'table' => self::TABLE_FE_USERS,
                    'scope' => self::SCOPE_FE_USERS,
                    'record' => $record,
                ];
            }
        }

        if ($this->getProvisionSettings()->isTargetEnabled(self::SCOPE_BE_USERS)) {
            $record = $this->findUserRecordByUidInTable(self::TABLE_BE_USERS, $uid);
            if ($record !== null) {
                return [
                    'table' => self::TABLE_BE_USERS,
                    'scope' => self::SCOPE_BE_USERS,
                    'record' => $record,
                ];
            }
        }

        return null;
    }

    /**
     * @param array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int} $existingRecord
     * @param list<array<string, mixed>> $operations
     * @return array{updates: array<string, int|string>, softDelete: bool}
     */
    private function compilePatchColumnUpdates(
        string $table,
        string $scope,
        array $existingRecord,
        array $operations
    ): array {
        $pathRegistry = $this->buildScimPathToColumnRegistry($scope, $table);
        $attributeMapping = $this->getMappingConfigurationService()->getAttributeMapping();
        $groupRolePath = $this->normalizePatchPath((string)($attributeMapping['group_role'] ?? 'groups'));

        $columnUpdates = [];
        $patchedActive = null;
        $softDelete = false;
        $firstNamePath = $this->normalizePatchPath((string)($attributeMapping['first_name'] ?? ''));
        $lastNamePath = $this->normalizePatchPath((string)($attributeMapping['last_name'] ?? ''));
        $backendGivenName = null;
        $backendFamilyName = null;

        foreach ($operations as $operation) {
            $operationType = strtolower(trim(MoUtilities::stringFromMixed($operation['op'] ?? null)));
            if (!in_array($operationType, ['add', 'replace', 'remove'], true)) {
                continue;
            }

            foreach ($this->extractPathsAndValuesFromOperation($operation) as $patchPath => $patchValue) {
                $normalizedPath = $this->normalizePatchPath($patchPath);

                if ($normalizedPath === 'active') {
                    $patchedActive = $operationType === 'remove'
                        ? false
                        : $this->coercePatchValueToBoolean($patchValue);
                    continue;
                }

                if (strtolower($normalizedPath) === 'externalid' && $this->tableSupportsExternalIdColumn($table)) {
                    $columnUpdates[self::USER_COLUMN_EXTERNAL_ID] = $operationType === 'remove'
                        ? ''
                        : $this->coerceScimValueToDatabaseString($patchValue);
                    continue;
                }

                if ($this->isGroupRolePatchPath($normalizedPath, $groupRolePath)) {
                    // Role mapping is Premium-only; incoming group patches are ignored.
                    continue;
                }

                if ($table === self::TABLE_BE_USERS && $firstNamePath !== '' && $normalizedPath === $firstNamePath) {
                    $backendGivenName = $operationType === 'remove'
                        ? ''
                        : $this->coerceScimValueToDatabaseString($patchValue);
                    continue;
                }

                if ($table === self::TABLE_BE_USERS && $lastNamePath !== '' && $normalizedPath === $lastNamePath) {
                    $backendFamilyName = $operationType === 'remove'
                        ? ''
                        : $this->coerceScimValueToDatabaseString($patchValue);
                    continue;
                }

                $databaseField = $pathRegistry[$normalizedPath] ?? null;
                if ($databaseField === null || !$this->isWritableDatabaseColumn($table, $databaseField)) {
                    continue;
                }

                $columnUpdates[$databaseField] = $operationType === 'remove'
                    ? ''
                    : $this->coerceScimValueToDatabaseString($patchValue);
            }
        }

        if ($table === self::TABLE_BE_USERS && ($backendGivenName !== null || $backendFamilyName !== null)) {
            $givenName = $backendGivenName ?? $existingRecord['first_name'];
            $familyName = $backendFamilyName ?? $existingRecord['last_name'];
            $displayName = trim($givenName . ' ' . $familyName);
            if ($displayName !== '') {
                $columnUpdates['realName'] = $displayName;
            }
        }

        if ($patchedActive !== null) {
            if ($patchedActive) {
                $columnUpdates[self::USER_LIFECYCLE_COLUMN_DISABLED] = 0;
                $columnUpdates[self::USER_LIFECYCLE_COLUMN_DELETED] = 0;
            } elseif (!$this->getProvisionSettings()->isDisableEnabled()) {
                $this->getLogger()->warning(
                    'SCIM sent active:false for userName "{userName}" but sync_disable_users is off; the deactivation was skipped.',
                    ['userName' => $existingRecord['username']]
                );
            } elseif ($this->getProvisionSettings()->isDeleteOnDeactivationEnabled()) {
                $softDelete = true;
            } else {
                $columnUpdates[self::USER_LIFECYCLE_COLUMN_DISABLED] = 1;
            }
        }

        if ($table === self::TABLE_FE_USERS && (isset($columnUpdates['first_name']) || isset($columnUpdates['last_name']))) {
            $firstName = (string)($columnUpdates['first_name'] ?? $existingRecord['first_name']);
            $lastName = (string)($columnUpdates['last_name'] ?? $existingRecord['last_name']);
            $displayName = trim($firstName . ' ' . $lastName);
            if ($displayName !== '') {
                $columnUpdates['name'] = $displayName;
            }
        }

        if ($columnUpdates !== []) {
            $columnUpdates['tstamp'] = time();
        }

        return [
            'updates' => $columnUpdates,
            'softDelete' => $softDelete,
        ];
    }

    /**
     * @return array<string, string> Normalized SCIM path => database column name.
     */
    private function buildScimPathToColumnRegistry(string $scope, string $table): array
    {
        $registry = [];
        $attributeMapping = $this->getMappingConfigurationService()->getAttributeMapping();

        $coreColumnMap = [
            'username' => 'username',
            'email' => 'email',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
        ];

        foreach ($coreColumnMap as $mappingKey => $databaseField) {
            if ($table === self::TABLE_BE_USERS && in_array($databaseField, ['first_name', 'last_name', 'name'], true)) {
                continue;
            }

            $configuredPath = trim((string)($attributeMapping[$mappingKey] ?? ''));
            if ($configuredPath === '') {
                continue;
            }

            $registry[$this->normalizePatchPath($configuredPath)] = $databaseField;
        }

        $displayColumn = $table === self::TABLE_FE_USERS ? 'name' : 'realName';
        if ($this->isWritableDatabaseColumn($table, $displayColumn)) {
            $registry[$this->normalizePatchPath('displayName')] = $displayColumn;
        }

        return $registry;
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function extractPathsAndValuesFromOperation(array $operation): array
    {
        $path = trim(MoUtilities::stringFromMixed($operation['path'] ?? null));
        $value = $operation['value'] ?? null;

        if ($path !== '') {
            if (is_array($value) && $this->isAssociativeArray($value)) {
                return $this->flattenScimPatchValue($path, $value);
            }

            return [$path => $value];
        }

        if (!is_array($value)) {
            return [];
        }

        return $this->flattenScimPatchValue('', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenScimPatchValue(string $prefix, mixed $value): array
    {
        if (!is_array($value)) {
            if ($prefix === '') {
                return [];
            }

            return [$prefix => $value];
        }

        if (!$this->isAssociativeArray($value)) {
            return $prefix === '' ? [] : [$prefix => $value];
        }

        $paths = [];
        foreach ($value as $key => $childValue) {
            $segment = (string)$key;
            $childPath = $prefix === '' ? $segment : $prefix . '.' . $segment;
            if (is_array($childValue) && $this->isAssociativeArray($childValue)) {
                $paths = array_merge($paths, $this->flattenScimPatchValue($childPath, $childValue));
                continue;
            }

            $paths[$childPath] = $childValue;
        }

        return $paths;
    }

    private function normalizePatchPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = (string)preg_replace('/\[[^\]]*]/', '', $path);
        $path = (string)preg_replace('/\.+/', '.', $path);

        return trim($path, '.');
    }

    private function isGroupRolePatchPath(string $normalizedPath, string $configuredGroupRolePath): bool
    {
        if ($normalizedPath === $configuredGroupRolePath) {
            return true;
        }

        return $normalizedPath === 'groups' || str_starts_with($normalizedPath, 'groups.');
    }

    private function coercePatchValueToBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        if (is_numeric($value)) {
            return (int)$value !== 0;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Marks a user row as deleted and returns the number of affected rows so callers can
     * verify the write actually persisted before reporting success.
     */
    private function softDeleteUserInTable(string $table, int $uid): int
    {
        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions($table);

        return (int)$queryBuilder
            ->update($table)
            ->set(self::USER_LIFECYCLE_COLUMN_DELETED, 1)
            ->set('tstamp', time())
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    /**
     * @param array<string, int|string> $columnUpdates
     */
    private function applyColumnUpdatesToUser(string $table, int $uid, array $columnUpdates): void
    {
        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions($table);
        $queryBuilder
            ->update($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            );

        $this->applyDatabaseDataToQueryBuilder($queryBuilder, $columnUpdates);
        $queryBuilder->executeStatement();
    }

    private function isWritableDatabaseColumn(string $table, string $databaseField): bool
    {
        // 'admin' and 'usergroup' are intentionally excluded: SCIM patch/mapping input must
        // never be able to grant TYPO3 backend admin rights or change group/role membership.
        $writableColumns = match ($table) {
            self::TABLE_FE_USERS => [
                'username',
                'email',
                'first_name',
                'last_name',
                'name',
                'middle_name',
                self::USER_LIFECYCLE_COLUMN_DISABLED,
            ],
            self::TABLE_BE_USERS => [
                'username',
                'email',
                'realName',
                self::USER_LIFECYCLE_COLUMN_DISABLED,
            ],
            default => [],
        };

        return in_array($databaseField, $writableColumns, true);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @return array{uid: int, created: bool}
     */
    private function createFrontendUser(ScimUserDto $user): array
    {
        $existingRecord = $this->findExistingUserRecordForTable(self::TABLE_FE_USERS, $user);
        if ($existingRecord !== null) {
            $isDisabled = $existingRecord['disable'] === 1;
            $isDeleted = $existingRecord['deleted'] === 1;

            if (!$isDisabled && !$isDeleted) {
                throw new \RuntimeException('Frontend user already exists for the given userName or email.');
            }

            // Reactivate only SCIM-managed users; otherwise create a new user.
            if ($this->findUserRecordByUidInTable(self::TABLE_FE_USERS, (int)$existingRecord['uid']) !== null) {
                return $this->reactivateExistingUserInTable(self::TABLE_FE_USERS, (int)$existingRecord['uid'], $user);
            }
        }

        $targetStoragePid = $this->getProvisionSettings()->getFrontendUsersStoragePid();
        $databaseData = $this->compileFrontendUserRecord($user, $targetStoragePid, time());

        try {
            $this->getQueryBuilderForTableWithoutRestrictions(self::TABLE_FE_USERS)
                ->insert(self::TABLE_FE_USERS)
                ->values($databaseData)
                ->executeStatement();
        } catch (UniqueConstraintViolationException) {
            return $this->resolveUserCreatedByConcurrentRequest(self::TABLE_FE_USERS, $user);
        }

        $uid = (int)$this->getConnectionPool()
            ->getConnectionForTable(self::TABLE_FE_USERS)
            ->lastInsertId();

        return ['uid' => $uid, 'created' => true];
    }

    /**
     * @return array{uid: int, created: bool}
     */
    private function createBackendUser(ScimUserDto $user): array
    {
        $existingRecord = $this->findExistingUserRecordForTable(self::TABLE_BE_USERS, $user);
        if ($existingRecord !== null) {
            $isDisabled = $existingRecord['disable'] === 1;
            $isDeleted = $existingRecord['deleted'] === 1;

            if (!$isDisabled && !$isDeleted) {
                throw new \RuntimeException('Backend user already exists for the given userName or email.');
            }

           // Only reactivate SCIM-managed users.
            if ($this->findUserRecordByUidInTable(self::TABLE_BE_USERS, (int)$existingRecord['uid']) !== null) {
                return $this->reactivateExistingUserInTable(self::TABLE_BE_USERS, (int)$existingRecord['uid'], $user);
            }
        }

        $targetStoragePid = $this->getProvisionSettings()->getBackendUsersStoragePid();
        $databaseData = $this->compileBackendUserRecord($user, $targetStoragePid, time());

        try {
            $this->getQueryBuilderForTableWithoutRestrictions(self::TABLE_BE_USERS)
                ->insert(self::TABLE_BE_USERS)
                ->values($databaseData)
                ->executeStatement();
        } catch (UniqueConstraintViolationException) {
            return $this->resolveUserCreatedByConcurrentRequest(self::TABLE_BE_USERS, $user);
        }

        $uid = (int)$this->getConnectionPool()
            ->getConnectionForTable(self::TABLE_BE_USERS)
            ->lastInsertId();

        return ['uid' => $uid, 'created' => true];
    }

    /**
     * A concurrent request won the race to insert this userName/email first (caught via the
     * unique constraint on `username`); resolve to the row it created instead of failing the
     * request or leaving a duplicate.
     *
     * @return array{uid: int, created: bool}
     */
    private function resolveUserCreatedByConcurrentRequest(string $table, ScimUserDto $user): array
    {
        $record = $this->findExistingUserRecordForTable($table, $user);
        if ($record === null) {
            throw new \RuntimeException(
                sprintf('Concurrent SCIM create for table "%s" could not be resolved to a row.', $table)
            );
        }

        return ['uid' => (int)$record['uid'], 'created' => false];
    }

    /**
     * @return array{uid: int, updated: bool, deactivationSkipped?: bool}
     */
    private function updateFrontendUser(ScimUserDto $user, ?string $resourceId = null): array
    {
        $uid = $this->resolveUserUidForUpdate(self::TABLE_FE_USERS, $user, $resourceId);
        if ($uid === null) {
            throw new \RuntimeException('Frontend user was not found for update.');
        }

        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions(self::TABLE_FE_USERS);
        $queryBuilder
            ->update(self::TABLE_FE_USERS)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            );

        $this->applyDatabaseDataToQueryBuilder(
            $queryBuilder,
            $this->compileFrontendUserUpdateData($user)
        );
        $queryBuilder->executeStatement();

        return $this->buildUpdateResult($uid, $user);
    }

    /**
     * @return array{uid: int, updated: bool, deactivationSkipped?: bool}
     */
    private function updateBackendUser(ScimUserDto $user, ?string $resourceId = null): array
    {
        $uid = $this->resolveUserUidForUpdate(self::TABLE_BE_USERS, $user, $resourceId);
        if ($uid === null) {
            throw new \RuntimeException('Backend user was not found for update.');
        }

        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions(self::TABLE_BE_USERS);
        $queryBuilder
            ->update(self::TABLE_BE_USERS)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            );

        $this->applyDatabaseDataToQueryBuilder(
            $queryBuilder,
            $this->compileBackendUserUpdateData($user)
        );
        $queryBuilder->executeStatement();

        return $this->buildUpdateResult($uid, $user);
    }

    /**
     * @return array{uid: int, updated: bool, deactivationSkipped?: bool}
     */
    private function buildUpdateResult(int $uid, ScimUserDto $user): array
    {
        $result = ['uid' => $uid, 'updated' => true];

        if ($this->isDeactivationSkipped($user)) {
            $result['deactivationSkipped'] = true;
        }

        return $result;
    }

    /**
     * Compiles fe_users insert row: core identity fields + dynamically mapped custom columns.
     *
     * @return array<string, int|string>
     */
    private function compileFrontendUserRecord(ScimUserDto $user, int $targetStoragePid, int $now): array
    {
        $mappedColumns = $this->buildMappedUserColumns($user);

        $databaseData = [
            'pid' => $targetStoragePid,
            'tstamp' => $now,
            'crdate' => $now,
            'username' => $mappedColumns['username'],
            'password' => $this->hashPassword($mappedColumns['username'], 'FE'),
            'email' => $mappedColumns['email'],
            'first_name' => $mappedColumns['first_name'],
            'last_name' => $mappedColumns['last_name'],
            'name' => $user->getDisplayName(),
            'disable' => $this->resolveDisableFlag($user),
            'deleted' => 0,
        ];

        $databaseData = $this->applyExternalIdToWriteSet($databaseData, self::TABLE_FE_USERS, $user->getExternalId(), false);
        $databaseData = $this->applyScimManagedFlagToWriteSet($databaseData, self::TABLE_FE_USERS);

        return $databaseData;
    }

    /**
     * Compiles be_users insert row: core identity fields + dynamically mapped custom columns.
     *
     * @return array<string, int|string>
     */
    private function compileBackendUserRecord(ScimUserDto $user, int $targetStoragePid, int $now): array
    {
        $mappedColumns = $this->buildMappedUserColumns($user);

        $databaseData = [
            'pid' => $targetStoragePid,
            'tstamp' => $now,
            'crdate' => $now,
            'username' => $mappedColumns['username'],
            'password' => $this->hashPassword($mappedColumns['username'], 'BE'),
            'email' => $mappedColumns['email'],
            'realName' => $user->getDisplayName(),
            'disable' => $this->resolveDisableFlag($user),
            'deleted' => 0,
        ];

        $databaseData = $this->applyExternalIdToWriteSet($databaseData, self::TABLE_BE_USERS, $user->getExternalId(), false);
        $databaseData = $this->applyScimManagedFlagToWriteSet($databaseData, self::TABLE_BE_USERS);

        return $databaseData;
    }

    /**
     * @return array<string, int|string>
     */
    private function compileFrontendUserUpdateData(ScimUserDto $user): array
    {
        $mappedColumns = $this->buildMappedUserColumns($user);

        $databaseData = [
            'tstamp' => time(),
            'username' => $mappedColumns['username'],
            'email' => $mappedColumns['email'],
            'first_name' => $mappedColumns['first_name'],
            'last_name' => $mappedColumns['last_name'],
            'name' => $user->getDisplayName(),
            'disable' => $this->resolveDisableFlag($user),
        ];

        if ($user->isActive()) {
            $databaseData[self::USER_LIFECYCLE_COLUMN_DELETED] = 0;
        }

        $databaseData = $this->applyExternalIdToWriteSet($databaseData, self::TABLE_FE_USERS, $user->getExternalId(), true);

        return $databaseData;
    }

    /**
     * @return array<string, int|string>
     */
    private function compileBackendUserUpdateData(ScimUserDto $user): array
    {
        $mappedColumns = $this->buildMappedUserColumns($user);

        $databaseData = [
            'tstamp' => time(),
            'username' => $mappedColumns['username'],
            'email' => $mappedColumns['email'],
            'realName' => $user->getDisplayName(),
            'disable' => $this->resolveDisableFlag($user),
        ];

        if ($user->isActive()) {
            $databaseData[self::USER_LIFECYCLE_COLUMN_DELETED] = 0;
        }

        $databaseData = $this->applyExternalIdToWriteSet($databaseData, self::TABLE_BE_USERS, $user->getExternalId(), true);

        return $databaseData;
    }

    /**
     * @param array<string, int|string> $databaseData
     */
    private function applyDatabaseDataToQueryBuilder(QueryBuilder $queryBuilder, array $databaseData): void
    {
        foreach ($databaseData as $column => $value) {
            if ($column === '') {
                continue;
            }
            $queryBuilder->set($column, $value);
        }
    }

    /**
     * Adds the SCIM externalId column to a write set, but only when the column actually exists in
     * the schema. On updates ($skipEmpty = true) a missing/blank externalId is ignored so a sync
     * that omits it never overwrites a previously stored value.
     *
     * @param array<string, int|string> $databaseData
     * @return array<string, int|string>
     */
    private function applyExternalIdToWriteSet(
        array $databaseData,
        string $table,
        ?string $externalId,
        bool $skipEmpty
    ): array {
        if (!$this->tableSupportsExternalIdColumn($table)) {
            return $databaseData;
        }

        $value = trim((string)($externalId ?? ''));
        if ($value === '' && $skipEmpty) {
            return $databaseData;
        }

        $databaseData[self::USER_COLUMN_EXTERNAL_ID] = $value;

        return $databaseData;
    }

    /**
     * Marks a write set as SCIM-owned, but only when the column actually exists in the schema
     * (pre-DB-compare installs keep working, just without authorization scoping until the
     * migration is applied). Never conditional on anything else — every row this extension
     * creates or reactivates is unconditionally SCIM-managed.
     *
     * @param array<string, int|string> $databaseData
     * @return array<string, int|string>
     */
    private function applyScimManagedFlagToWriteSet(array $databaseData, string $table): array
    {
        if ($this->tableSupportsScimManagedColumn($table)) {
            $databaseData[self::USER_COLUMN_SCIM_MANAGED] = 1;
        }

        return $databaseData;
    }

    /**
     * Reports whether the externalId column is present on the given user table. Guards every
     * read/write so the extension keeps working before the database schema migration that adds
     * the column has been applied.
     */
    private function tableSupportsExternalIdColumn(string $table): bool
    {
        return $this->tableSupportsColumn($table, self::USER_COLUMN_EXTERNAL_ID);
    }

    /**
     * Reports whether the tx_scim_managed ownership marker is present on the given user table.
     * Every SCIM-managed-scoped lookup treats "column missing" as "fail closed" (see
     * findUserRecordByIdentityValueInternal()/findUserRecordByUidInTable()) rather than
     * falling back to unscoped matching.
     */
    private function tableSupportsScimManagedColumn(string $table): bool
    {
        return $this->tableSupportsColumn($table, self::USER_COLUMN_SCIM_MANAGED);
    }

    /**
     * Schema introspection is cached via TYPO3's `runtime` cache rather than a bare PHP static
     * property, so it is checked at most once per request regardless of how many service
     * instances are created, while still resetting correctly under every execution model TYPO3
     * supports (including long-running worker runtimes, where a static property would
     * otherwise never re-check the schema after a migration is applied without a full
     * worker/process restart).
     */
    private function tableSupportsColumn(string $table, string $column): bool
    {
        $cache = $this->getRuntimeCache();
        $cacheKey = 'scim_column_support_' . $table . '_' . $column;
        if ($cache->has($cacheKey)) {
            return (bool)$cache->get($cacheKey);
        }

        $supported = false;
        try {
            $schemaManager = $this->getConnectionPool()->getConnectionForTable($table)->createSchemaManager();
            foreach ($schemaManager->listTableColumns($table) as $tableColumn) {
                if (strtolower($tableColumn->getName()) === $column) {
                    $supported = true;
                    break;
                }
            }
        } catch (\Throwable) {
            $supported = false;
        }

        $cache->set($cacheKey, $supported);

        return $supported;
    }

    private function getRuntimeCache(): FrontendInterface
    {
        return GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
    }

    /**
     * Returns the conditions that identify SCIM-managed users eligible
     * for SCIM read, update, patch, and delete operations.
     *
     * @return list<\TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression|string>
     */
    private function buildScimOwnershipConstraints(QueryBuilder $queryBuilder, string $table): array
    {
        $constraints = [
            $queryBuilder->expr()->eq(
                self::USER_COLUMN_SCIM_MANAGED,
                $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)
            ),
        ];

        if ($table === self::TABLE_BE_USERS) {
            $constraints[] = $queryBuilder->expr()->eq(
                'admin',
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
            );
        }

        return $constraints;
    }

    private function coerceScimValueToDatabaseString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) || is_numeric($value)) {
            return trim((string)$value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    /**
     * Resolves an update's write target by identity value, scoped to SCIM-managed rows only —
     * unlike findExistingUserRecordForTable() (create-time duplicate detection), this must
     * never resolve to a pre-existing, non-SCIM-managed account.
     */
    private function findScimManagedUid(string $table, ScimUserDto $user): ?int
    {
        foreach ($this->collectUserIdentityLookupValues($user) as $identityValue) {
            $record = $this->findUserRecordByIdentityValue($table, $identityValue);
            if ($record !== null) {
                return (int)$record['uid'];
            }
        }

        return null;
    }

    private function resolveUserUidForUpdate(string $table, ScimUserDto $user, ?string $resourceId): ?int
    {
        $resourceId = trim((string)$resourceId);
        if ($resourceId !== '') {
            $context = $this->resolveUserContextByResourceId($resourceId);
            if ($context !== null && $context['table'] === $table) {
                return (int)$context['record']['uid'];
            }
        }

        return $this->findScimManagedUid($table, $user);
    }

    /**
     * Finds existing users for create-time duplicate detection.
     * Includes both SCIM-managed and non-SCIM-managed users.
     *
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int, deleted: int}|null
     */
    private function findExistingUserRecordForTable(string $table, ScimUserDto $user): ?array
    {
        foreach ($this->collectUserIdentityLookupValues($user) as $identityValue) {
            $record = $this->findAnyExistingRecordByIdentityValue($table, $identityValue);
            if ($record !== null) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Identity values in lookup order: externalId first (stable IdP reference), then userName and email.
     *
     * @return list<string>
     */
    private function collectUserIdentityLookupValues(ScimUserDto $user): array
    {
        $values = [];

        $externalId = trim((string)($user->getExternalId() ?? ''));
        if ($externalId !== '') {
            $values[] = $externalId;
        }

        $userName = trim($user->getUserName());
        if ($userName !== '' && !in_array($userName, $values, true)) {
            $values[] = $userName;
        }

        $email = trim($user->getEmail());
        if ($email !== '' && !in_array($email, $values, true)) {
            $values[] = $email;
        }

        return $values;
    }

    /**
     * Re-provisions a previously disabled or soft-deleted user instead of inserting a duplicate row.
     *
     * @return array{uid: int, created: false, reactivated: true}
     */
    private function reactivateExistingUserInTable(string $table, int $uid, ScimUserDto $user): array
    {
        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions($table);
        $queryBuilder
            ->update($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            );

        $databaseData = match ($table) {
            self::TABLE_FE_USERS => $this->compileFrontendUserUpdateData($user),
            self::TABLE_BE_USERS => $this->compileBackendUserUpdateData($user),
            default => throw new \RuntimeException(sprintf('Unsupported user table "%s".', $table)),
        };

        $databaseData[self::USER_LIFECYCLE_COLUMN_DISABLED] = 0;
        $databaseData[self::USER_LIFECYCLE_COLUMN_DELETED] = 0;
        $databaseData = $this->applyScimManagedFlagToWriteSet($databaseData, $table);

        $this->applyDatabaseDataToQueryBuilder($queryBuilder, $databaseData);
        $queryBuilder->executeStatement();

        return ['uid' => $uid, 'created' => false, 'reactivated' => true];
    }

    /**
     * Finds a user by username, email, or external ID. When $scimManagedOnly
     * is true, only SCIM-managed users are considered. Use false only for
     * create-time duplicate checks.
     *
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int, deleted: int}|null
     */
    private function findUserRecordByIdentityValueInternal(string $table, string $identityValue, bool $scimManagedOnly): ?array
    {
        if ($scimManagedOnly && !$this->tableSupportsScimManagedColumn($table)) {
            // The ownership marker doesn't exist yet (database compare not run) — fail closed
            // rather than fall back to matching any pre-existing account.
            return null;
        }

        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions($table);

        $identityConditions = [
            $queryBuilder->expr()->eq(
                'username',
                $queryBuilder->createNamedParameter($identityValue, Connection::PARAM_STR)
            ),
            $queryBuilder->expr()->eq(
                'email',
                $queryBuilder->createNamedParameter($identityValue, Connection::PARAM_STR)
            ),
        ];

       // Match the stored externalId so IdP deprovisioning can locate the user.
        if ($this->tableSupportsExternalIdColumn($table)) {
            $identityConditions[] = $queryBuilder->expr()->eq(
                self::USER_COLUMN_EXTERNAL_ID,
                $queryBuilder->createNamedParameter($identityValue, Connection::PARAM_STR)
            );
        }

        $constraints = [$queryBuilder->expr()->or(...$identityConditions)];

        if ($scimManagedOnly) {
            $constraints = [...$constraints, ...$this->buildScimOwnershipConstraints($queryBuilder, $table)];
        }

        $selectFields = $table === self::TABLE_FE_USERS
            ? ['uid', 'username', 'email', 'first_name', 'last_name', 'disable', 'deleted']
            : ['uid', 'username', 'email', 'realName', 'disable', 'deleted'];

        $row = $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->where(...$constraints)
            ->orderBy(self::USER_LIFECYCLE_COLUMN_DELETED, 'ASC')
            ->addOrderBy(self::USER_LIFECYCLE_COLUMN_DISABLED, 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $this->normalizeUserRecord($table, $row);
    }

    /**
     * SCIM-managed-scoped identity lookup — use for resolving the target of any read, update,
     * patch, or delete driven by the SCIM API.
     *
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}|null
     */
    private function findUserRecordByIdentityValue(string $table, string $identityValue): ?array
    {
        return $this->findUserRecordByIdentityValueInternal($table, $identityValue, true);
    }

    /**
     * Unscoped identity lookup — use only for create-time duplicate detection (see
     * findExistingUserRecordForTable()), never to resolve a read/update/patch/delete target.
     *
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int, deleted: int}|null
     */
    private function findAnyExistingRecordByIdentityValue(string $table, string $identityValue): ?array
    {
        return $this->findUserRecordByIdentityValueInternal($table, $identityValue, false);
    }

    /**
     * Every caller of this method resolves the target of a SCIM read, update, patch, or
     * delete — so it is unconditionally scoped to rows this extension created (and, for
     * be_users, excludes admin accounts). If the ownership marker doesn't exist yet (database
     * compare not run), this fails closed rather than matching any pre-existing account.
     *
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int}|null
     */
    private function findUserRecordByUidInTable(string $table, int $uid): ?array
    {
        if (!$this->tableSupportsScimManagedColumn($table)) {
            return null;
        }

        $queryBuilder = $this->getQueryBuilderForTableWithoutRestrictions($table);

        $constraints = [
            $queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
            ),
            ...$this->buildScimOwnershipConstraints($queryBuilder, $table),
        ];

        $selectFields = $table === self::TABLE_FE_USERS
            ? ['uid', 'username', 'email', 'first_name', 'last_name', 'disable', 'deleted']
            : ['uid', 'username', 'email', 'realName', 'disable', 'deleted'];

        $row = $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $this->normalizeUserRecord($table, $row);
    }

    /**
     * @param array<string, mixed>|false $row
     * @return array{uid: int, username: string, email: string, first_name: string, last_name: string, disable: int, deleted: int}|null
     */
    private function normalizeUserRecord(string $table, array|false $row): ?array
    {
        if (!is_array($row) || !isset($row['uid'])) {
            return null;
        }

        if ($table === self::TABLE_BE_USERS) {
            $realName = trim(MoUtilities::stringFromMixed($row['realName'] ?? null));
            $nameParts = $realName !== '' ? preg_split('/\s+/', $realName, 2) : ['', ''];

            return [
                'uid' => MoUtilities::intFromMixed($row['uid']),
                'username' => MoUtilities::stringFromMixed($row['username'] ?? null),
                'email' => MoUtilities::stringFromMixed($row['email'] ?? null),
                'first_name' => (string)($nameParts[0] ?? ''),
                'last_name' => (string)($nameParts[1] ?? ''),
                'disable' => MoUtilities::intFromMixed($row['disable'] ?? null),
                'deleted' => MoUtilities::intFromMixed($row['deleted'] ?? null),
            ];
        }

        return [
            'uid' => MoUtilities::intFromMixed($row['uid']),
            'username' => MoUtilities::stringFromMixed($row['username'] ?? null),
            'email' => MoUtilities::stringFromMixed($row['email'] ?? null),
            'first_name' => MoUtilities::stringFromMixed($row['first_name'] ?? null),
            'last_name' => MoUtilities::stringFromMixed($row['last_name'] ?? null),
            'disable' => MoUtilities::intFromMixed($row['disable'] ?? null),
            'deleted' => MoUtilities::intFromMixed($row['deleted'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isActiveUserRecord(array $record): bool
    {
        return MoUtilities::intFromMixed($record['disable'] ?? null) === 0
            && MoUtilities::intFromMixed($record['deleted'] ?? null) === 0;
    }

    private function resolveDisableFlag(ScimUserDto $user): int
    {
        if ($user->isActive()) {
            return 0;
        }

        if (!$this->getProvisionSettings()->isDisableEnabled()) {
            $this->getLogger()->warning(
                'SCIM sent active:false for userName "{userName}" but sync_disable_users is off; the deactivation was skipped.',
                ['userName' => $user->getUserName()]
            );

            return 0;
        }

        return 1;
    }

    /**
     * True when an IdP-requested deactivation (active:false) was ignored because
     * sync_disable_users is disabled in SCIM sync settings.
     */
    private function isDeactivationSkipped(ScimUserDto $user): bool
    {
        return !$user->isActive() && !$this->getProvisionSettings()->isDisableEnabled();
    }

    /**
     * @return array{username: string, email: string, first_name: string, last_name: string}
     */
    private function buildMappedUserColumns(ScimUserDto $user): array
    {
        return $this->getAttributeMapper()->buildCoreUserColumns($user);
    }

    private function hashPassword(string $seed, string $mode = 'FE'): string
    {
        $hashInstance = $this->getPasswordHashFactory()->getDefaultHashInstance($mode);
        $plainPassword = bin2hex(random_bytes(16)) . $seed;

        $hashedPassword = $hashInstance->getHashedPassword($plainPassword);
        if ($hashedPassword === null) {
            throw new \RuntimeException('Failed to generate a password hash for the provisioned user.');
        }

        return $hashedPassword;
    }

    private function getQueryBuilderForTableWithoutRestrictions(string $table): QueryBuilder
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        // Include disabled and soft-deleted users in SCIM lookups to prevent duplicate records.
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    private function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool ??= GeneralUtility::makeInstance(ConnectionPool::class);
    }

    private function getProvisionSettings(): ScimProvisionSettings
    {
        return $this->provisionSettings ??= GeneralUtility::makeInstance(ScimProvisionSettings::class);
    }

    private function getMappingConfigurationService(): ScimMappingConfigurationService
    {
        return $this->mappingConfigurationService ??= GeneralUtility::makeInstance(ScimMappingConfigurationService::class);
    }

    private function getAttributeMapper(): ScimAttributeMapper
    {
        return $this->attributeMapper ??= GeneralUtility::makeInstance(ScimAttributeMapper::class);
    }

    private function getPasswordHashFactory(): PasswordHashFactory
    {
        return $this->passwordHashFactory ??= GeneralUtility::makeInstance(PasswordHashFactory::class);
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger ??= GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
    }
}
