<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service\Endpoint;

use JsonException;
use Miniorange\Scim\Dto\ScimRouteContext;
use Miniorange\Scim\Dto\ScimUserDto;
use Miniorange\Scim\Helper\MoUtilities;
use Miniorange\Scim\Helper\ScimConfig;
use Miniorange\Scim\Service\ScimUserSyncLimitService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ScimRequestHandler
{
    /** Default page size for GET /Users when no `count` query parameter is supplied. */
    private const DEFAULT_LIST_PAGE_SIZE = 100;

    /** Matches the `filter.maxResults` value already declared in ServiceProviderConfig. */
    private const MAX_LIST_PAGE_SIZE = 200;

    /**
     * Rejects request bodies larger than this before they're ever passed to json_decode() —
     * generous for a SCIM User resource (typically a few KB), but caps the worst case where
     * no other layer (PHP's post_max_size, the webserver) happens to be configured tightly.
     */
    private const MAX_REQUEST_BODY_BYTES = 1_048_576;

    private ?ScimAuthService $authService = null;
    private ?ScimPayloadParser $payloadParser = null;
    private ?ScimProvisionSettings $provisionSettings = null;
    private ?ScimUserSyncService $userSyncService = null;
    private ?ScimJsonResponseFactory $responseFactory = null;
    private ?ScimRouteResolver $routeResolver = null;
    private ?ScimFilterParser $filterParser = null;
    private ?ScimUserSyncLimitService $userSyncLimitService = null;

    public function __construct(
        ?ScimAuthService $authService = null,
        ?ScimPayloadParser $payloadParser = null,
        ?ScimProvisionSettings $provisionSettings = null,
        ?ScimUserSyncService $userSyncService = null,
        ?ScimJsonResponseFactory $responseFactory = null,
        ?ScimRouteResolver $routeResolver = null,
        ?ScimFilterParser $filterParser = null,
        ?ScimUserSyncLimitService $userSyncLimitService = null,
    ) {
        $this->authService = $authService;
        $this->payloadParser = $payloadParser;
        $this->provisionSettings = $provisionSettings;
        $this->userSyncService = $userSyncService;
        $this->responseFactory = $responseFactory;
        $this->routeResolver = $routeResolver;
        $this->filterParser = $filterParser;
        $this->userSyncLimitService = $userSyncLimitService;
    }

    /**
     * Main SCIM entry point: resolves the route, enforces bearer auth, and dispatches
     * to the matching resource handler (Users, Groups, or discovery endpoints).
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            ScimConfig::ensureRow();

            $route = $this->getRouteResolver()->resolve($request);
            if ($route === null) {
                return $this->getResponseFactory()->error('SCIM endpoint was not found.', 404);
            }

            if (!$this->getAuthService()->isAuthorized($request)) {
                return $this->getResponseFactory()->unauthorized();
            }

            if ($route->getResource() === 'Root') {
                return $this->handleRootDiscovery();
            }

            return match ($route->getResource()) {
                'Users' => $this->handleUsers($request, $route),
                'ServiceProviderConfig' => $this->handleServiceProviderConfig($request),
                'Schemas' => $this->handleSchemas($request),
                'ResourceTypes' => $this->handleResourceTypes($request),
                default => $this->getResponseFactory()->error(
                    sprintf('SCIM resource "%s" is not supported.', $route->getResource()),
                    404
                ),
            };
        } catch (Throwable $exception) {
            return $this->createDebugResponse($exception, 400);
        }
    }

    /**
     * Builds a JSON error response that includes exception details for troubleshooting.
     */
    public function createDebugResponse(Throwable $exception, int $statusCode = 400): ResponseInterface
    {
        return $this->getResponseFactory()->debugThrowable($exception, $statusCode);
    }

    /**
     * Returns the SCIM API root listing available User and Group endpoints.
     */
    private function handleRootDiscovery(): ResponseInterface
    {
        return $this->getResponseFactory()->success([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 1,
            'startIndex' => 1,
            'itemsPerPage' => 1,
            'Resources' => [
                ['name' => 'User', 'endpoint' => '/Users'],
            ],
        ]);
    }

    /**
     * Routes /Users requests by HTTP method. When no sync targets are enabled, only
     * read requests are allowed and they return empty or not-found responses.
     */
    private function handleUsers(ServerRequestInterface $request, ScimRouteContext $route): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        if ($this->getProvisionSettings()->getEnabledTargets() === []) {
            if ($method === 'GET') {
                if ($route->getResourceId() !== null) {
                    return $this->getResponseFactory()->error('User was not found.', 404);
                }

                return $this->buildEmptyUserListResponse();
            }

            return $this->getResponseFactory()->forbidden('No provisioning targets are enabled in SCIM configuration.');
        }

        return match ($method) {
            'GET' => $this->handleUsersRead($request, $route),
            'POST' => $this->handleUsersCreate($request),
            'PUT' => $this->handleUsersUpdate($request, $route),
            'PATCH' => $this->handleUsersPatch($request, $route),
            default => $this->getResponseFactory()->methodNotAllowed(),
        };
    }

    /**
     * Handles GET /Users: returns a single user by TYPO3 uid, or searches by a
     * supported equality filter (e.g. userName eq "value").
     */
    private function handleUsersRead(ServerRequestInterface $request, ScimRouteContext $route): ResponseInterface
    {
        if ($route->getResourceId() !== null) {
            $record = $this->getUserSyncService()->findUserRecordByUid($route->getResourceId());
            if ($record === null) {
                return $this->getResponseFactory()->error('User was not found.', 404);
            }

            return $this->getResponseFactory()->success(
                $this->withResourceLocation($this->getUserSyncService()->formatUserAsScimResource($record), $request)
            );
        }

        $filter = $request->getQueryParams()['filter'] ?? null;
        if (!is_string($filter) || trim($filter) === '') {
            return $this->buildPagedUserListResponse($request);
        }

        try {
            $parsedFilter = $this->getFilterParser()->parseEqualityFilter($filter);
        } catch (\InvalidArgumentException $exception) {
            return $this->getResponseFactory()->badRequest($exception->getMessage());
        }

        if ($parsedFilter === null) {
            return $this->buildPagedUserListResponse($request);
        }

        $record = $this->getUserSyncService()->findUserRecordByFilterAttribute(
            $parsedFilter['attribute'],
            $parsedFilter['value']
        );
        if ($record === null) {
            return $this->buildEmptyUserListResponse();
        }

        return $this->buildUserListResponse([
            $this->withResourceLocation($this->getUserSyncService()->formatUserAsScimResource($record), $request),
        ]);
    }

    /**
     * Builds a paginated SCIM ListResponse for all SCIM-managed users across enabled
     * provisioning targets, honoring RFC 7644 startIndex/count pagination.
     */
    private function buildPagedUserListResponse(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $startIndex = max(1, MoUtilities::intFromMixed($queryParams['startIndex'] ?? 1, 1));
        $requestedCount = $queryParams['count'] ?? null;
        $count = $requestedCount === null? self::DEFAULT_LIST_PAGE_SIZE: max(0, MoUtilities::intFromMixed($requestedCount));
        $count = min($count, self::MAX_LIST_PAGE_SIZE);

        $targets = $this->getProvisionSettings()->getEnabledTargets();

        $totalsByTarget = [];
        $totalResults = 0;
        foreach ($targets as $target) {
            $total = $this->getUserSyncService()->countScimManagedUserRecords($target);
            $totalsByTarget[$target] = $total;
            $totalResults += $total;
        }

        $resources = [];
        $remaining = $count;
        $offset = $startIndex - 1;

        foreach ($targets as $target) {
            if ($remaining <= 0) {
                break;
            }

            $targetTotal = $totalsByTarget[$target];
            if ($offset >= $targetTotal) {
                $offset -= $targetTotal;
                continue;
            }

            $records = $this->getUserSyncService()->findScimManagedUserRecords($target, $remaining, $offset);
            foreach ($records as $record) {
                $resources[] = $this->withResourceLocation(
                    $this->getUserSyncService()->formatUserAsScimResource($record),
                    $request
                );
            }

            $remaining -= count($records);
            $offset = 0;
        }

        return $this->getResponseFactory()->success([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $totalResults,
            'startIndex' => $startIndex,
            'itemsPerPage' => count($resources),
            'Resources' => $resources,
        ]);
    }

    /**
     * Handles POST /Users: creates a backend user in all enabled provisioning targets
     * and returns 409 when a matching userName or email already exists.
     */
    private function handleUsersCreate(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->getProvisionSettings()->isCreateEnabled()) {
            return $this->getResponseFactory()->forbidden('User creation is disabled in SCIM sync settings.');
        }

        try {
            $user = $this->parseUserPayload($request);
        } catch (JsonException $exception) {
            return $this->getResponseFactory()->badRequest($exception->getMessage());
        }

        $existingUserUid = $this->getUserSyncService()->findExistingUserUidInAnyTarget($user);
        if ($existingUserUid !== null) {
            return $this->getResponseFactory()->conflict(
                'User already exists for the provided userName or email.',
                (string)$existingUserUid
            );
        }

        if (!$this->getUserSyncLimitService()->canProvisionNewUser()) {
            return $this->getResponseFactory()->forbidden(
                'You have reached the free UserSync limit. Upgrade to Premium to continue syncing users.'
            );
        }

        try {
            $syncResults = $this->getUserSyncService()->createUser($user);
        } catch (RuntimeException $exception) {
            $conflictUserUid = $this->getUserSyncService()->findExistingUserUidInAnyTarget($user);

            return $this->getResponseFactory()->conflict(
                $exception->getMessage(),
                $conflictUserUid !== null ? (string)$conflictUserUid : null
            );
        } catch (Throwable $exception) {
            return $this->createDebugResponse($exception, 400);
        }

        $this->getUserSyncLimitService()->recordSuccessfulProvisioning($syncResults);

        return $this->buildUserResponse($user, $syncResults, 201, $request);
    }

    /**
     * Handles PUT /Users/{id}: replaces user data, resolving the target user either
     * from the path resource id or from userName/email in the request body.
     */
    private function handleUsersUpdate(ServerRequestInterface $request, ScimRouteContext $route): ResponseInterface
    {
        if (!$this->getProvisionSettings()->isUpdateEnabled()) {
            return $this->getResponseFactory()->forbidden('User update is disabled in SCIM sync settings.');
        }

        try {
            $user = $this->parseUserPayload($request);
        } catch (JsonException $exception) {
            return $this->getResponseFactory()->badRequest($exception->getMessage());
        }

        if ($route->getResourceId() !== null) {
            if ($this->getUserSyncService()->resolveUserContextByResourceId($route->getResourceId()) === null) {
                return $this->getResponseFactory()->error('User was not found for update.', 404);
            }
        } elseif ($this->getUserSyncService()->findExistingUserRecordInAnyTarget($user) === null) {
            // Fast-fail if the user does not exist. SCIM ownership is validated in updateUser().
            return $this->getResponseFactory()->error('User was not found for update.', 404);
        }

        try {
            $syncResults = $this->getUserSyncService()->updateUser($user, $route->getResourceId());
        } catch (\UnexpectedValueException $exception) {
            // Unexpected server error; return a debug response without exposing internal details.
            return $this->createDebugResponse($exception, 500);
        } catch (RuntimeException $exception) {
            return $this->getResponseFactory()->error($exception->getMessage(), 404);
        } catch (Throwable $exception) {
            return $this->createDebugResponse($exception, 400);
        }

        return $this->buildUserResponse($user, $syncResults, 200, $request);
    }

    /**
     * Handles PATCH /Users/{id}: applies SCIM PatchOp operations (replace, add, remove)
     * and returns 204 when the user is deactivated or deleted via patch.
     */
    private function handleUsersPatch(ServerRequestInterface $request, ScimRouteContext $route): ResponseInterface
    {
        if (!$this->getProvisionSettings()->isUpdateEnabled()) {
            return $this->getResponseFactory()->forbidden('User update is disabled in SCIM sync settings.');
        }

        if ($route->getResourceId() === null) {
            return $this->getResponseFactory()->badRequest('PATCH requires a user resource id in the request path.');
        }

        try {
            $patchPayload = $this->parseJsonPayload($request);
        } catch (JsonException $exception) {
            return $this->getResponseFactory()->badRequest($exception->getMessage());
        }

        if (!$this->getUserSyncService()->isPatchOpPayload($patchPayload)) {
            return $this->getResponseFactory()->badRequest('PATCH request must use the SCIM PatchOp schema.');
        }

        try {
            $patchResult = $this->getUserSyncService()->applyPatchOperations($route->getResourceId(), $patchPayload);
        } catch (\InvalidArgumentException $exception) {
            return $this->getResponseFactory()->badRequest($exception->getMessage());
        } catch (\UnexpectedValueException $exception) {
            // See handleUsersUpdate(): must be caught ahead of RuntimeException (which it
            // extends) so a wrapped driver/database failure is never echoed back directly.
            return $this->createDebugResponse($exception, 500);
        } catch (RuntimeException $exception) {
            return $this->getResponseFactory()->error($exception->getMessage(), 404);
        } catch (Throwable $exception) {
            return $this->createDebugResponse($exception, 400);
        }

        if ($patchResult['deleted']) {
            return $this->getResponseFactory()->noContent();
        }

        $patchedRecord = $patchResult['record'];
        if ($patchedRecord === null) {
            throw new RuntimeException('SCIM patch result is missing the updated user record.');
        }

        return $this->getResponseFactory()->success(
            $this->withResourceLocation(
                $this->getUserSyncService()->formatUserAsScimResource($patchedRecord),
                $request
            )
        );
    }

    /**
     * Returns SCIM ServiceProviderConfig describing supported features (patch, filter, auth).
     */
    private function handleServiceProviderConfig(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return $this->getResponseFactory()->methodNotAllowed();
        }

        return $this->getResponseFactory()->success([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => 'https://plugins.miniorange.com/',
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => self::MAX_LIST_PAGE_SIZE],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [
                [
                    'type' => 'oauthbearertoken',
                    'name' => 'OAuth Bearer Token',
                    'description' => 'Authentication scheme using the OAuth Bearer Token Standard',
                    'specUri' => 'https://www.rfc-editor.org/info/rfc6750',
                    'documentationUri' => 'https://plugins.miniorange.com/',
                    'primary' => true,
                ],
            ],
        ]);
    }

    /**
     * Returns the SCIM schema definitions exposed by this provider (User schema only), with
     * attribute metadata (RFC 7643 §4.1) limited to what this extension actually reads/maps
     * (userName, name, emails, active, externalId) so an IdP's attribute-mapping UI has
     * something real to introspect.
     */
    private function handleSchemas(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return $this->getResponseFactory()->methodNotAllowed();
        }

         return $this->getResponseFactory()->success([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 1,
            'startIndex' => 1,
            'itemsPerPage' => 1,
            'Resources' => [
                [
                    'id' => 'urn:ietf:params:scim:schemas:core:2.0:User',
                    'name' => 'User',
                    'description' => 'User Account',
                    'attributes' => ScimUserSchemaProvider::coreUserAttributes(),
                    'meta' => [
                        'resourceType' => 'Schema',
                        'location' => '/Schemas/urn:ietf:params:scim:schemas:core:2.0:User',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Returns SCIM ResourceType metadata for the User endpoint. Group is intentionally not
     * listed — group provisioning is not implemented, so it must not be discoverable as a
     * supported resource type.
     */
    private function handleResourceTypes(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return $this->getResponseFactory()->methodNotAllowed();
        }

        return $this->getResponseFactory()->success([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 1,
            'startIndex' => 1,
            'itemsPerPage' => 1,
            'Resources' => [
                [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    'id' => 'User',
                    'name' => 'User',
                    'endpoint' => '/Users',
                    'schema' => 'urn:ietf:params:scim:schemas:core:2.0:User',
                    'schemaExtensions' => [],
                ],
            ],
        ]);
    }

    /**
     * Decodes the request body into a ScimUserDto; rejects empty bodies on PUT updates.
     *
     * @throws JsonException
     */
    private function parseUserPayload(ServerRequestInterface $request): ScimUserDto
    {
        $rawBody = $this->readRequestBody($request);
        if (trim($rawBody) === '' && strtoupper($request->getMethod()) === 'PUT') {
            throw new JsonException('SCIM request body is empty.');
        }

        return $this->getPayloadParser()->parse($rawBody);
    }

    /**
     * Decodes the raw request body as a JSON object (used for PATCH PatchOp payloads).
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function parseJsonPayload(ServerRequestInterface $request): array
    {
        $rawBody = $this->readRequestBody($request);
        if (trim($rawBody) === '') {
            throw new JsonException('SCIM request body is empty.');
        }

        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new JsonException('SCIM request body must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Reads the raw request body, rejecting anything over MAX_REQUEST_BODY_BYTES before it's
     * ever handed to json_decode() — an oversized-body resource-exhaustion guard independent
     * of whatever PHP/webserver-level body-size limits happen to be configured.
     *
     * @throws JsonException
     */
    private function readRequestBody(ServerRequestInterface $request): string
    {
        $rawBody = $request->getBody()->getContents();
        if (strlen($rawBody) > self::MAX_REQUEST_BODY_BYTES) {
            throw new JsonException(
                sprintf('SCIM request body exceeds the %d byte limit.', self::MAX_REQUEST_BODY_BYTES)
            );
        }

        return $rawBody;
    }

    /**
     * Builds the SCIM User resource returned after create/update; falls back to a
     * minimal payload when sync results do not include formatted user data.
     *
     * @param array<string, mixed> $syncResults
     */
    private function buildUserResponse(
        ScimUserDto $user,
        array $syncResults,
        int $statusCode,
        ServerRequestInterface $request
    ): ResponseInterface {
        $payload = $this->getUserSyncService()->buildScimUserResponseFromSyncResults($syncResults);
        if ($payload !== null) {
            if ($user->getExternalId() !== null) {
                $payload['externalId'] = $user->getExternalId();
            }

            return $this->getResponseFactory()->success($this->withResourceLocation($payload, $request), $statusCode);
        }

        $payload = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id' => $user->getExternalId() ?? $user->getUserName(),
            'userName' => $user->getUserName(),
            'name' => [
                'givenName' => $user->getFirstName(),
                'familyName' => $user->getLastName(),
            ],
            'active' => $user->isActive(),
            'meta' => ['resourceType' => 'User'],
            'syncResults' => $syncResults,
        ];

        if ($user->getExternalId() !== null) {
            $payload['externalId'] = $user->getExternalId();
        }

        if ($user->getEmail() !== '') {
            $payload['emails'] = [
                [
                    'value' => $user->getEmail(),
                    'primary' => true,
                ],
            ];
        }

        return $this->getResponseFactory()->success($this->withResourceLocation($payload, $request), $statusCode);
    }

    /**
     * Wraps one or more User resources in a SCIM ListResponse envelope.
     *
     * @param list<array<string, mixed>> $resources
     */
    private function buildUserListResponse(array $resources): ResponseInterface
    {
        $totalResults = count($resources);

        return $this->getResponseFactory()->success([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $totalResults,
            'startIndex' => 1,
            'itemsPerPage' => $totalResults,
            'Resources' => $resources,
        ]);
    }

    /** Returns a SCIM ListResponse with zero User resources. */
    private function buildEmptyUserListResponse(): ResponseInterface
    {
        return $this->buildUserListResponse([]);
    }

    /**
     * Adds the absolute `meta.location` URI to a SCIM User resource without
     * overwriting existing `meta` attributes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withResourceLocation(array $payload, ServerRequestInterface $request): array
    {
        $id = $payload['id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return $payload;
        }

        $baseUrl = rtrim($this->getRouteResolver()->buildPublicBaseUrl($request), '/');
        $meta = $payload['meta'] ?? [];
        $meta = is_array($meta) ? $meta : [];
        $meta['location'] = $baseUrl . '/Users/' . rawurlencode((string)$id);
        $payload['meta'] = $meta;

        return $payload;
    }

    private function getAuthService(): ScimAuthService
    {
        return $this->authService ??= GeneralUtility::makeInstance(ScimAuthService::class);
    }

    private function getPayloadParser(): ScimPayloadParser
    {
        return $this->payloadParser ??= GeneralUtility::makeInstance(ScimPayloadParser::class);
    }

    private function getProvisionSettings(): ScimProvisionSettings
    {
        return $this->provisionSettings ??= GeneralUtility::makeInstance(ScimProvisionSettings::class);
    }

    private function getUserSyncService(): ScimUserSyncService
    {
        return $this->userSyncService ??= GeneralUtility::makeInstance(ScimUserSyncService::class);
    }

    private function getResponseFactory(): ScimJsonResponseFactory
    {
        return $this->responseFactory ??= GeneralUtility::makeInstance(ScimJsonResponseFactory::class);
    }

    private function getRouteResolver(): ScimRouteResolver
    {
        return $this->routeResolver ??= GeneralUtility::makeInstance(ScimRouteResolver::class);
    }

    private function getFilterParser(): ScimFilterParser
    {
        return $this->filterParser ??= GeneralUtility::makeInstance(ScimFilterParser::class);
    }

    private function getUserSyncLimitService(): ScimUserSyncLimitService
    {
        return $this->userSyncLimitService ??= GeneralUtility::makeInstance(ScimUserSyncLimitService::class);
    }
}
