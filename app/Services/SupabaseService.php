<?php

namespace App\Services;

use App\Jobs\StoreAuditLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseService
{
    private const MAX_AVATAR_SIZE_BYTES = 2 * 1024 * 1024;

    /** @var list<string> */
    private const PROFILE_FORM_COLUMNS = [
        'avatar_url',
        'first_name',
        'grade_level',
        'last_name',
        'leaderboard_alias',
        'show_on_leaderboard',
    ];

    private const AVATAR_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private string $url;
    private string $anonKey;
    private string $serviceKey;

    public function __construct()
    {
        $this->url        = rtrim((string) config('services.supabase.url'), '/');
        $this->anonKey    = (string) config('services.supabase.anon_key');
        $this->serviceKey = (string) config('services.supabase.service_key');
    }

    // ── Auth ──────────────────────────────────────────────

    public function signIn(string $email, string $password): array
    {
        $response = $this->request()->withHeaders([
            'apikey'       => $this->anonKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->url}/auth/v1/token?grant_type=password", [
            'email'    => $email,
            'password' => $password,
        ]);

        return $response->json() ?? [];
    }

    public function signOut(string $accessToken): bool
    {
        if (!$this->isValidBearerToken($accessToken)) {
            return false;
        }

        return $this->request()->withHeaders([
            'apikey' => $this->anonKey,
            'Authorization' => "Bearer {$accessToken}",
        ])->withQueryParameters(['scope' => 'global'])
          ->post("{$this->url}/auth/v1/logout")
          ->successful();
    }

    public function signUp(
        string $email,
        string $password,
        string $role,
        string $first_name,
        string $last_name,
        ?int $grade_level = null,
        ?string $redirectTo = null
    ): array {
        if (!in_array($role, ['student', 'pending_teacher'], true)) {
            throw new \InvalidArgumentException('Invalid public registration role.');
        }

        $request = $this->request()->withHeaders([
            'apikey'       => $this->anonKey,
            'Content-Type' => 'application/json',
        ]);
        if ($redirectTo !== null) {
            $request = $request->withQueryParameters(['redirect_to' => $redirectTo]);
        }

        $response = $request->post("{$this->url}/auth/v1/signup", [
            'email'    => $email,
            'password' => $password,
            'data' => [
                'role'        => $role,
                'first_name'  => $first_name,
                'last_name'   => $last_name,
                'grade_level' => $grade_level,
            ],
        ]);

        return $this->authResponse($response);
    }

    public function deleteAuthUser(string $userId): bool
    {
        $this->assertUuid($userId);

        return $this->request()->withHeaders([
            'apikey' => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type' => 'application/json',
        ])->delete("{$this->url}/auth/v1/admin/users/{$userId}")
          ->successful();
    }

    public function uploadAvatar(string $userId, ?UploadedFile $file): ?string
    {
        if (!$file
            || !$file->isValid()
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId) !== 1
        ) {
            return null;
        }

        $size = $file->getSize();
        if (!is_int($size) || $size < 1 || $size > self::MAX_AVATAR_SIZE_BYTES) {
            return null;
        }

        $realPath = $file->getRealPath();
        $image = is_string($realPath) ? @getimagesize($realPath) : false;
        $detectedMime = strtolower((string) ($image['mime'] ?? ''));
        $fileMime = strtolower((string) $file->getMimeType());
        $extension = self::AVATAR_TYPES[$detectedMime] ?? null;
        if ($image === false
            || $extension === null
            || $fileMime !== $detectedMime
        ) {
            return null;
        }

        $content = file_get_contents($realPath);
        if (!is_string($content) || strlen($content) !== $size) {
            return null;
        }

        try {
            $randomName = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return null;
        }

        $path = 'avatars/' . strtolower($userId) . "_{$randomName}.{$extension}";

        $upload = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type'  => $detectedMime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ])->withBody($content, $detectedMime)
          ->post("{$this->url}/storage/v1/object/{$path}");

        if ($upload->successful()) {
            return "{$this->url}/storage/v1/object/public/{$path}";
        }

        return null;
    }

    public function deleteAvatarByUrl(?string $avatarUrl, string $expectedUserId): bool
    {
        if (!$avatarUrl
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $expectedUserId) !== 1
        ) {
            return false;
        }

        $publicPrefix = "{$this->url}/storage/v1/object/public/";
        if (!str_starts_with($avatarUrl, $publicPrefix)) {
            return false;
        }

        $path = substr($avatarUrl, strlen($publicPrefix));
        if (preg_match(
            '#^avatars/([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})_[A-Za-z0-9_-]{1,64}\.(?:jpe?g|png|webp)$#i',
            $path,
            $matches
        ) !== 1 || !hash_equals(strtolower($expectedUserId), strtolower($matches[1]))) {
            return false;
        }

        $response = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
        ])->delete("{$this->url}/storage/v1/object/{$path}");

        return $response->successful();
    }

    public function updateProfile(string $userId, array $data): array
    {
        $this->assertUuid($userId);

        if ($data === []) {
            throw new \InvalidArgumentException('A profile update requires at least one field.');
        }

        foreach (array_keys($data) as $column) {
            if (!is_string($column) || !in_array($column, self::PROFILE_FORM_COLUMNS, true)) {
                throw new \InvalidArgumentException('The profile update contains a server-controlled field.');
            }
        }

        $response = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ])->withQueryParameters(['id' => "eq.{$userId}"])
          ->patch("{$this->url}/rest/v1/profiles", $data);

        return $this->responseRows($response);
    }

    public function resetPassword(string $email, ?string $redirectTo = null): array
    {
        $request = $this->request()->withHeaders([
            'apikey'       => $this->anonKey,
            'Content-Type' => 'application/json',
        ]);
        if ($redirectTo !== null) {
            $request = $request->withQueryParameters(['redirect_to' => $redirectTo]);
        }

        $response = $request->post("{$this->url}/auth/v1/recover", [
            'email' => $email,
        ]);

        return $this->authResponse($response);
    }

    public function verifyRecoveryToken(string $tokenHash): array
    {
        if ($tokenHash === ''
            || strlen($tokenHash) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $tokenHash) === 1
        ) {
            throw new \InvalidArgumentException('Invalid password recovery token.');
        }

        $response = $this->request()->withHeaders([
            'apikey' => $this->anonKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->url}/auth/v1/verify", [
            'token_hash' => $tokenHash,
            'type' => 'recovery',
        ]);

        return $this->authResponse($response);
    }

    public function verifyEmailToken(string $tokenHash, string $type): array
    {
        if ($tokenHash === ''
            || strlen($tokenHash) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $tokenHash) === 1
            || !in_array($type, ['email', 'email_change'], true)
        ) {
            throw new \InvalidArgumentException('Invalid email confirmation token.');
        }

        $response = $this->request()->withHeaders([
            'apikey' => $this->anonKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->url}/auth/v1/verify", [
            'token_hash' => $tokenHash,
            'type' => $type,
        ]);

        return $this->authResponse($response);
    }

    public function updateAuthUser(
        string $token,
        array $attributes,
        ?string $redirectTo = null
    ): array {
        if (!$this->isValidBearerToken($token)) {
            throw new \InvalidArgumentException('Invalid authentication token.');
        }
        if ($attributes === []
            || array_diff(array_keys($attributes), ['email', 'password']) !== []
        ) {
            throw new \InvalidArgumentException('Unsupported authentication account update.');
        }

        $request = $this->request()->withHeaders([
            'apikey' => $this->anonKey,
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ]);
        if ($redirectTo !== null) {
            $request = $request->withQueryParameters(['redirect_to' => $redirectTo]);
        }

        return $this->authResponse(
            $request->put("{$this->url}/auth/v1/user", $attributes)
        );
    }

    // ── Database (uses JWT token from logged-in user) ─────

    public function select(string $table, string $query = '*', array $filters = [], ?string $token = null): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $this->assertSelectExpression($query);
        $key = $this->dataApiBearerKey($token);

        $request = $this->request()->withHeaders([
            'apikey'        => $this->anonKey,
            'Authorization' => "Bearer {$key}",
        ])->withQueryParameters(['select' => $query]);

        foreach ($filters as $column => $value) {
            $this->assertFilterColumn((string) $column);
            $request = $request->withQueryParameters([
                $column => 'eq.' . $this->validatedOperatorValue('eq', $value),
            ]);
        }

        $response = $request->get("{$this->url}/rest/v1/{$table}");
        return $this->responseRows($response);
    }

    public function insert(string $table, array $data, ?string $token = null): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $key = $this->dataApiBearerKey($token);

        $response = $this->request()->withHeaders([
            'apikey'        => $this->anonKey,
            'Authorization' => "Bearer {$key}",
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ])->post("{$this->url}/rest/v1/{$table}", $data);

        return $this->responseRows($response);
    }

    public function update(string $table, array $data, array $filters, ?string $token = null): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $this->assertMutationFilters($filters);
        $key = $this->dataApiBearerKey($token);

        $request = $this->request()->withHeaders([
            'apikey'        => $this->anonKey,
            'Authorization' => "Bearer {$key}",
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ]);

        foreach ($filters as $column => $value) {
            $this->assertFilterColumn((string) $column);
            $request = $request->withQueryParameters([
                $column => 'eq.' . $this->validatedOperatorValue('eq', $value),
            ]);
        }

        $response = $request->patch("{$this->url}/rest/v1/{$table}", $data);
        return $this->responseRows($response);
    }

    public function delete(string $table, array $filters, ?string $token = null): bool
    {
        $this->assertResourceIdentifier($table, 'table');
        $this->assertMutationFilters($filters);
        $key = $this->dataApiBearerKey($token);
        $request = $this->request()->withHeaders([
            'apikey'        => $this->anonKey,
            'Authorization' => "Bearer {$key}",
        ]);

        foreach ($filters as $column => $value) {
            $this->assertFilterColumn((string) $column);
            $request = $request->withQueryParameters([
                $column => 'eq.' . $this->validatedOperatorValue('eq', $value),
            ]);
        }

        $response = $request->delete("{$this->url}/rest/v1/{$table}");

        return $response->successful();
    }

    // ── Admin (bypasses RLS entirely) ─────────────────────

    public function adminSelect(string $table, string $query = '*', array $filters = []): array
    {
        return $this->adminSelectResult($table, $query, $filters)['data'];
    }

    public function adminSelectResult(string $table, string $query = '*', array $filters = []): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $this->assertSelectExpression($query);
        $params = $this->buildAdminSelectParams($query, $this->activeRecordFilters($table, $filters));

        $response = $this->adminReadResponse($table, $params, $filters);

        if (!$response->successful()) {
            $message = $this->databaseErrorMessage($response);

            return [
                'data' => [],
                'error' => $message ?: "Database query on {$table} failed with status {$response->status()}.",
                'status' => $response->status(),
            ];
        }

        return [
            'data' => $this->responseRows($response),
            'error' => null,
            'status' => $response->status(),
        ];
    }

    /**
     * Run a server-side paginated PostgREST query and return its exact total.
     * Operator filters use: ['operator' => 'ilike', 'value' => '*fractions*'].
     */
    public function adminSelectPage(
        string $table,
        string $query = '*',
        array $filters = [],
        int $limit = 24,
        int $offset = 0
    ): array {
        $this->assertResourceIdentifier($table, 'table');
        $this->assertSelectExpression($query);
        $params = $this->buildAdminSelectParams($query, $this->activeRecordFilters($table, $filters));
        $params['limit'] = max(1, min($limit, 100));
        $params['offset'] = max(0, $offset);

        $response = $this->adminReadResponse($table, $params, $filters, true);

        $total = 0;
        $contentRange = (string) $response->header('Content-Range');
        if (preg_match('/\/(\d+)$/', $contentRange, $matches)) {
            $total = (int) $matches[1];
        }

        $rows = $this->responseRows($response);

        return [
            'data'  => $rows,
            'total' => $total,
            'error' => $response->successful() ? null : "Database pagination on {$table} failed with status {$response->status()}.",
        ];
    }

    /**
     * Paginate rows with non-null priority values first without requiring a
     * generated database column. Each partition keeps true server-side
     * pagination and uses the supplied secondary ordering.
     */
    public function adminSelectPrioritizedPage(
        string $table,
        string $query,
        array $filters,
        string $priorityColumn,
        string $secondaryOrder,
        int $limit = 24,
        int $offset = 0
    ): array {
        unset($filters['order'], $filters['limit'], $filters['offset']);

        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $priorityFilters = $filters;
        $priorityFilters[$priorityColumn] = ['operator' => 'not.is', 'value' => 'null'];
        $priorityFilters['order'] = $secondaryOrder;

        $remainingFilters = $filters;
        $remainingFilters[$priorityColumn] = ['operator' => 'is', 'value' => 'null'];
        $remainingFilters['order'] = $secondaryOrder;

        $priorityTotal = $this->adminCount($table, $priorityFilters);
        $remainingTotal = $this->adminCount($table, $remainingFilters);
        $rows = [];

        if ($offset < $priorityTotal) {
            $priorityLimit = min($limit, $priorityTotal - $offset);
            $priorityPage = $this->adminSelectPage(
                $table,
                $query,
                $priorityFilters,
                $priorityLimit,
                $offset
            );
            $rows = $priorityPage['data'];
        }

        $remainingSlots = $limit - count($rows);
        if ($remainingSlots > 0) {
            $remainingOffset = max(0, $offset - $priorityTotal);
            if ($remainingOffset < $remainingTotal) {
                $remainingPage = $this->adminSelectPage(
                    $table,
                    $query,
                    $remainingFilters,
                    $remainingSlots,
                    $remainingOffset
                );
                $rows = array_merge($rows, $remainingPage['data']);
            }
        }

        return [
            'data' => $rows,
            'total' => $priorityTotal + $remainingTotal,
        ];
    }

    public function adminCount(string $table, array $filters = []): int
    {
        return $this->adminCountResult($table, $filters)['count'];
    }

    /** Return an exact count without hiding provider errors as zero. */
    public function adminCountResult(string $table, array $filters = []): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $params = $this->buildAdminSelectParams('id', $this->activeRecordFilters($table, $filters));
        $params['limit'] = 1;

        $response = $this->adminReadResponse($table, $params, $filters, true);

        if (!$response->successful()) {
            $message = $this->databaseErrorMessage($response);
            return [
                'count' => 0,
                'error' => $message ?: "Database count on {$table} failed with status {$response->status()}.",
                'status' => $response->status(),
            ];
        }

        $contentRange = (string) $response->header('Content-Range');
        if (preg_match('/\/(\d+)$/', $contentRange, $matches) !== 1) {
            return [
                'count' => 0,
                'error' => "Database count on {$table} did not include an exact total.",
                'status' => $response->status(),
            ];
        }

        return ['count' => (int) $matches[1], 'error' => null, 'status' => $response->status()];
    }

    private function buildAdminSelectParams(string $query, array $filters): array
    {
        $params = ['select' => $query];
        $allowedOperators = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'is', 'in', 'not.is'];

        foreach ($filters as $column => $value) {
            if (in_array($column, ['order', 'limit', 'offset', 'or', 'and'], true)) {
                $params[$column] = $this->validatedControlParameter($column, $value);
                continue;
            }

            $this->assertFilterColumn((string) $column);

            if (is_array($value) && isset($value['operator'], $value['value'])) {
                $operator = (string) $value['operator'];
                if (!in_array($operator, $allowedOperators, true)) {
                    throw new \InvalidArgumentException("Unsupported Supabase filter operator: {$operator}");
                }

                $params[$column] = $operator . '.'
                    . $this->validatedOperatorValue($operator, $value['value']);
                continue;
            }

            $params[$column] = 'eq.' . $this->validatedOperatorValue('eq', $value);
        }

        return $params;
    }

    /** Service-role reads bypass RLS: ordinary pages must not list Trash.
     * Recovery pages deliberately provide an explicit deleted_at filter.
     */
    private function activeRecordFilters(string $table, array $filters): array
    {
        if (in_array($table, ['classes', 'quizzes'], true) && !array_key_exists('deleted_at', $filters)) {
            $filters['deleted_at'] = ['operator' => 'is', 'value' => 'null'];
        }
        return $filters;
    }

    private function adminReadResponse(string $table, array $params, array $filters, bool $count = false)
    {
        $headers = ['apikey' => $this->serviceKey, 'Authorization' => "Bearer {$this->serviceKey}"];
        if ($count) {
            $headers['Prefer'] = 'count=exact';
        }
        $response = $this->request()->withHeaders($headers)->get("{$this->url}/rest/v1/{$table}", $params);
        // Rolling-deploy compatibility ONLY for a genuinely absent trash
        // column. Never retry writes, permission failures, outages or explicit
        // Trash reads without their restriction. Missing migration = no trash
        // flags exist; the new mutation RPCs still fail closed until applied.
        if (in_array($table, ['classes', 'quizzes'], true) && !array_key_exists('deleted_at', $filters)
            && ($response->json('code') ?? '') === '42703'
            && str_contains((string) $response->json('message'), 'deleted_at')) {
            unset($params['deleted_at']);
            $response = $this->request()->withHeaders($headers)->get("{$this->url}/rest/v1/{$table}", $params);
        }
        return $response;
    }

    public function adminUpdate(string $table, array $data, array $filters): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $this->assertMutationFilters($filters);
        $query = $this->buildAdminSelectParams('*', $filters);
        unset($query['select'], $query['order'], $query['limit'], $query['offset']);

        $response = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ])->withQueryParameters($query)
          ->patch("{$this->url}/rest/v1/{$table}", $data);

        return $this->responseRows($response);
    }

    public function adminInsert(string $table, array $data): array
    {
        return $this->adminInsertResult($table, $data)['data'];
    }

    public function adminInsertResult(string $table, array $data): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $response = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ])->post("{$this->url}/rest/v1/{$table}", $data);

        if (!$response->successful()) {
            $message = $this->databaseErrorMessage($response);

            return [
                'data' => [],
                'error' => $message ?: "Database insert into {$table} failed with status {$response->status()}.",
                'status' => $response->status(),
            ];
        }

        return [
            'data' => $this->responseRows($response),
            'error' => null,
            'status' => $response->status(),
        ];
    }

    public function adminDeleteResult(string $table, array $filters): array
    {
        $this->assertResourceIdentifier($table, 'table');
        $this->assertMutationFilters($filters);
        $query = [];
        foreach ($filters as $column => $value) {
            $this->assertFilterColumn((string) $column);
            $query[$column] = 'eq.' . $this->validatedOperatorValue('eq', $value);
        }

        $response = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Prefer'        => 'return=representation',
        ])->withQueryParameters($query)
          ->delete("{$this->url}/rest/v1/{$table}");

        if (!$response->successful()) {
            return [
                'data' => [],
                'error' => $this->databaseErrorMessage($response)
                    ?: "Database delete from {$table} failed with status {$response->status()}.",
                'status' => $response->status(),
            ];
        }

        return [
            'data' => $this->responseRows($response),
            'error' => null,
            'status' => $response->status(),
        ];
    }

    public function adminUpsert(string $table, array $data, string $onConflict): array
    {
        $this->assertResourceIdentifier($table, 'table');
        foreach (explode(',', $onConflict) as $column) {
            $this->assertFilterColumn(trim($column));
        }

        $response = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type'  => 'application/json',
            'Prefer'        => 'resolution=merge-duplicates,return=representation',
        ])->withQueryParameters(['on_conflict' => $onConflict])
          ->post("{$this->url}/rest/v1/{$table}", $data);

        return $this->responseRows($response);
    }

    public function adminRpc(string $function, array $arguments = []): array
    {
        return $this->adminRpcResult($function, $arguments)['data'];
    }

    public function adminRpcResult(string $function, array $arguments = []): array
    {
        $this->assertResourceIdentifier($function, 'function');
        $response = $this->request()->withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type'  => 'application/json',
        ])->post("{$this->url}/rest/v1/rpc/{$function}", $arguments);

        if (!$response->successful()) {
            $message = $this->databaseErrorMessage($response);

            return [
                'data' => [],
                'error' => $message ?: "Database function {$function} failed with status {$response->status()}.",
                'status' => $response->status(),
            ];
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return [
                'data' => [],
                'error' => "Database function {$function} returned an invalid response.",
                'status' => $response->status(),
            ];
        }

        return [
            'data' => array_is_list($payload) ? $payload : [$payload],
            'error' => null,
            'status' => $response->status(),
        ];
    }

    public function setAuthUserSuspended(string $userId, bool $suspended): bool
    {
        $this->assertUuid($userId);
        $response = $this->request()->withHeaders([
            'apikey' => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type' => 'application/json',
        ])->put("{$this->url}/auth/v1/admin/users/{$userId}", [
            'ban_duration' => $suspended ? '876000h' : 'none',
        ]);

        return $response->successful();
    }

    /**
     * Store the privileged action intent and its pending security event in one
     * database transaction. Call this before changing an account or role.
     */
    public function beginPrivilegedAudit(
        array $actor,
        string $action,
        string $targetType,
        string|int|null $targetId = null,
        array $metadata = []
    ): ?string {
        try {
            $result = $this->adminRpcResult('create_privileged_audit_intent', [
                'p_actor_id' => $actor['id'] ?? null,
                'p_action' => $action,
                'p_target_type' => $targetType,
                'p_target_id' => $targetId === null ? null : (string) $targetId,
                'p_metadata' => $metadata,
            ]);
            $intentId = (string) ($result['data'][0]['intent_id'] ?? '');
            if ($result['error'] === null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $intentId) === 1) {
                return $intentId;
            }

            Log::critical('A privileged action was blocked because its audit intent could not be stored.', [
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId === null ? null : (string) $targetId,
                'status' => $result['status'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Log::critical('A privileged action was blocked by an audit storage failure.', [
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId === null ? null : (string) $targetId,
                'exception' => $exception::class,
            ]);
        }

        return null;
    }

    /** A failed call leaves the pending outbox row visible to System Health. */
    public function completePrivilegedAudit(
        string $intentId,
        bool $succeeded,
        array $metadata = [],
        ?string $error = null
    ): bool {
        try {
            $result = $this->adminRpcResult('complete_privileged_audit_intent', [
                'p_intent_id' => $intentId,
                'p_succeeded' => $succeeded,
                'p_metadata' => $metadata,
                'p_error' => $error,
            ]);
            if ($result['error'] === null && (bool) ($result['data'][0]['completed'] ?? false)) {
                return true;
            }

            Log::critical('A privileged audit intent is still pending.', [
                'intent_id' => $intentId,
                'succeeded' => $succeeded,
                'status' => $result['status'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Log::critical('A privileged audit intent could not be finalized.', [
                'intent_id' => $intentId,
                'succeeded' => $succeeded,
                'exception' => $exception::class,
            ]);
        }

        return false;
    }

    public function audit(
        array $actor,
        string $action,
        string $targetType,
        string|int|null $targetId = null,
        array $metadata = []
    ): bool {
        if (app()->runningInConsole()) {
            return $this->storeAudit($actor, $action, $targetType, $targetId, $metadata);
        }

        StoreAuditLog::dispatch($actor, $action, $targetType, $targetId, $metadata)
            ->onConnection('deferred');

        return true;
    }

    public function storeAudit(
        array $actor,
        string $action,
        string $targetType,
        string|int|null $targetId = null,
        array $metadata = []
    ): bool {
        try {
            $actorName = trim((string) ($actor['name'] ?? ''));
            if ($actorName === '') {
                $actorName = trim((string) ($actor['first_name'] ?? '') . ' ' . (string) ($actor['last_name'] ?? ''));
            }
            $category = $action === 'page.viewed' ? 'activity' : 'security';
            $payload = [
                'actor_id' => $actor['id'] ?? null,
                'actor_role' => $actor['role'] ?? null,
                'actor_name' => $actorName !== '' ? mb_substr($actorName, 0, 200) : null,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId === null ? null : (string) $targetId,
                'metadata' => $metadata,
                'event_category' => $category,
                'severity' => $category === 'activity' ? 'info' : (str_starts_with($action, 'user.') || str_starts_with($action, 'teacher.') ? 'high' : 'medium'),
                'outcome' => 'succeeded',
            ];
            $result = $this->adminInsertResult('audit_logs', $payload);
            if ($result['error'] !== null && str_contains(strtolower((string) $result['error']), 'column')) {
                $result = $this->adminInsertResult('audit_logs', array_intersect_key($payload, array_flip([
                    'actor_id', 'actor_role', 'action', 'target_type', 'target_id', 'metadata',
                ])));
            }

            return isset($result['data'][0]['id']);
        } catch (\Throwable $exception) {
            // Audit logging is important, but it must never turn an otherwise
            // successful user action into a 500 response.
            Log::warning('An audit event could not be stored.', [
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId === null ? null : (string) $targetId,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    public function adminDelete(string $table, array $filters): bool
    {
        return $this->adminDeleteResult($table, $filters)['error'] === null;
    }

    public function updatePassword(string $token, string $password): array
    {
        return $this->updateAuthUser($token, ['password' => $password]);
    }

    private function request(): PendingRequest
    {
        // Keep invalid production credentials fail-closed for every outbound
        // request without making public pages unavailable during container
        // startup or a deployment environment refresh.
        $this->assertProductionConfiguration();

        return Http::connectTimeout((int) config('services.supabase.connect_timeout', 5))
            ->timeout((int) config('services.supabase.request_timeout', 15))
            ->acceptJson();
    }

    private function assertProductionConfiguration(): void
    {
        if (!app()->isProduction()) {
            return;
        }

        $parts = parse_url($this->url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
        ) {
            throw new \RuntimeException('Production Supabase configuration requires a valid HTTPS project URL.');
        }

        if (strlen($this->anonKey) < 20
            || strlen($this->serviceKey) < 20
            || hash_equals($this->anonKey, $this->serviceKey)
        ) {
            throw new \RuntimeException('Production Supabase credentials are missing or invalid.');
        }
    }

    private function assertResourceIdentifier(string $identifier, string $kind): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier) !== 1) {
            throw new \InvalidArgumentException("Invalid Supabase {$kind} identifier.");
        }
    }

    private function assertFilterColumn(string $column): void
    {
        if (preg_match('/^[a-z_][a-z0-9_.]*$/i', $column) !== 1) {
            throw new \InvalidArgumentException('Invalid Supabase filter column.');
        }
    }

    private function assertMutationFilters(array $filters): void
    {
        if ($filters === []) {
            throw new \InvalidArgumentException('Refusing an unscoped Supabase mutation.');
        }

        foreach (array_keys($filters) as $column) {
            if (in_array((string) $column, ['order', 'limit', 'offset', 'or', 'and'], true)) {
                throw new \InvalidArgumentException('Supabase mutations require explicit column filters.');
            }
        }
    }

    private function assertSelectExpression(string $query): void
    {
        if ($query === ''
            || strlen($query) > 2000
            || preg_match('/^[a-z0-9_*,().:!]+$/i', $query) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid Supabase select expression.');
        }
    }

    private function assertUuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid user identifier.');
        }
    }

    private function isValidBearerToken(mixed $token): bool
    {
        return is_string($token)
            && $token !== ''
            && strlen($token) <= 8192
            && preg_match('/[\x00-\x20\x7F]/', $token) !== 1;
    }

    private function dataApiBearerKey(?string $token): string
    {
        $key = $token ?? $this->anonKey;
        if (!$this->isValidBearerToken($key)) {
            throw new \InvalidArgumentException('Invalid Data API authentication token.');
        }

        return $key;
    }

    private function validatedControlParameter(string $name, mixed $value): int|string
    {
        if ($name === 'limit') {
            $limit = filter_var($value, FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 1000) {
                throw new \InvalidArgumentException('Invalid Supabase query limit.');
            }

            return $limit;
        }

        if ($name === 'offset') {
            $offset = filter_var($value, FILTER_VALIDATE_INT);
            if ($offset === false || $offset < 0 || $offset > 1000000) {
                throw new \InvalidArgumentException('Invalid Supabase query offset.');
            }

            return $offset;
        }

        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Invalid Supabase query control value.');
        }

        $expression = (string) $value;
        if ($name === 'order') {
            if (preg_match(
                '/^[a-z_][a-z0-9_]*(?:\.(?:asc|desc))?(?:\.(?:nullsfirst|nullslast))?(?:,[a-z_][a-z0-9_]*(?:\.(?:asc|desc))?(?:\.(?:nullsfirst|nullslast))?)*$/i',
                $expression
            ) !== 1) {
                throw new \InvalidArgumentException('Invalid Supabase ordering expression.');
            }

            return $expression;
        }

        $logicalClause = '[a-z_][a-z0-9_.]*\.(?:eq|neq|gt|gte|lt|lte|like|ilike|is|not\.is)\.[^,()\\\\\x00-\x1F\x7F]+';
        if (strlen($expression) > 2000
            || preg_match('/^\((?:' . $logicalClause . ')(?:,' . $logicalClause . ')*\)$/iuD', $expression) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid Supabase logical filter expression.');
        }

        return $expression;
    }

    private function validatedOperatorValue(string $operator, mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Invalid Supabase filter value.');
        }

        $expression = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };

        if ($expression === ''
            || strlen($expression) > 2000
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $expression) === 1
        ) {
            throw new \InvalidArgumentException('Invalid Supabase filter value.');
        }

        if ($operator === 'in'
            && preg_match('/^\([a-z0-9_-]+(?:,[a-z0-9_-]+)*\)$/iD', $expression) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid Supabase in-filter value.');
        }

        if (in_array($operator, ['is', 'not.is'], true)
            && !in_array(strtolower($expression), ['null', 'true', 'false', 'unknown'], true)
        ) {
            throw new \InvalidArgumentException('Invalid Supabase null/boolean filter value.');
        }

        if (in_array($operator, ['like', 'ilike'], true)
            && preg_match('/[(),]/u', $expression) === 1
        ) {
            throw new \InvalidArgumentException('Invalid Supabase pattern filter value.');
        }

        return $expression;
    }

    private function authResponse($response): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];

        return [
            'successful' => $response->successful(),
            'data' => $data,
            'error' => $response->successful()
                ? null
                : ($data['error_description'] ?? $data['msg'] ?? $data['message'] ?? $data['error'] ?? 'Authentication request failed.'),
            'status' => $response->status(),
        ];
    }

    private function responseRows($response): array
    {
        if (!$response->successful()) {
            return [];
        }

        $rows = $response->json();

        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    private function databaseErrorMessage($response): ?string
    {
        $error = $response->json();
        if (!is_array($error)) {
            $body = trim((string) $response->body());
            return $body !== '' ? $body : null;
        }

        $parts = array_values(array_unique(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            [
                $error['message'] ?? null,
                $error['details'] ?? null,
                $error['hint'] ?? null,
                $error['code'] ?? null,
            ]
        ))));

        return $parts !== [] ? implode(' | ', $parts) : null;
    }
}
