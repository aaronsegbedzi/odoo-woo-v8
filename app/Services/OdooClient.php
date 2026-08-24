<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Odoo JSON-RPC client — PHP 8.x compatible replacement for robroypt/odoo-client.
 *
 * Communicates with Odoo via the standard /jsonrpc endpoint instead of the
 * deprecated XML-RPC functions removed in PHP 8.0.
 *
 * Public API is intentionally identical to robroypt/odoo-client so existing
 * callers (OdooProduct, OdooCategory, OdooPOS) need only a use-statement swap.
 */
class OdooClient
{
    private GuzzleClient $http;
    private string $jsonRpcUrl;
    private string $database;
    private string $user;
    private string $password;
    private ?int $uid = null;
    private int $maxRetries;
    private int $requestId = 0;

    /**
     * @param string $host       Odoo base URL. Legacy "/xmlrpc/2" suffixes are stripped automatically.
     * @param string $database   Odoo database name.
     * @param string $user       Odoo login (email).
     * @param string $password   Odoo password / API key.
     * @param int    $maxRetries Number of retry attempts on network errors (default 3).
     */
    public function __construct(
        string $host,
        string $database,
        string $user,
        string $password,
        int $maxRetries = 3
    ) {
        // Strip legacy XML-RPC path so existing ODOO_URL values still work unchanged.
        $baseUrl = rtrim(preg_replace('#/xmlrpc.*$#', '', $host), '/');
        $this->jsonRpcUrl = $baseUrl . '/jsonrpc';
        $this->database   = $database;
        $this->user       = $user;
        $this->password   = $password;
        $this->maxRetries = max(1, $maxRetries);

        $this->http = new GuzzleClient([
            'timeout'         => 120,
            'connect_timeout' => 30,
            'verify'          => (bool) config('app.odoo_verify_ssl', true),
            'headers'         => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ]);
    }

    // ─── Public API (mirrors robroypt/odoo-client) ────────────────────────────

    public function version(): array
    {
        return $this->call('common', 'version', []) ?? [];
    }

    /**
     * Search and read records in one call.
     *
     * @param string   $model    Odoo model name (e.g. 'product.template')
     * @param array    $criteria Domain filter (pass [] or null for no filter)
     * @param array    $fields   Fields to fetch; empty = all fields
     * @param int      $limit    Maximum records to return
     * @param int      $offset   Number of records to skip (for paging large reads)
     */
    public function search_read(string $model, ?array $criteria = [], array $fields = [], int $limit = 100, int $offset = 0): array
    {
        return $this->executeKw($model, 'search_read', [$criteria ?? []], [
            'fields' => $fields,
            'limit'  => $limit,
            'offset' => $offset,
        ]) ?? [];
    }

    /**
     * Search and return matching record IDs.
     */
    public function search(string $model, array $criteria, int $offset = 0, int $limit = 100): array
    {
        return $this->executeKw($model, 'search', [$criteria], [
            'offset' => $offset,
            'limit'  => $limit,
        ]) ?? [];
    }

    /**
     * Count records matching criteria.
     */
    public function search_count(string $model, array $criteria): int
    {
        return (int) ($this->executeKw($model, 'search_count', [$criteria]) ?? 0);
    }

    /**
     * Read specific records by ID.
     */
    public function read(string $model, array $ids, array $fields = []): array
    {
        return $this->executeKw($model, 'read', [$ids], ['fields' => $fields]) ?? [];
    }

    /**
     * Create a new record.
     *
     * @return int Created record ID
     */
    public function create(string $model, array $data): int
    {
        return (int) $this->executeKw($model, 'create', [$data]);
    }

    /**
     * Update a record by ID.
     */
    public function write(string $model, int $id, array $fields): bool
    {
        return (bool) $this->executeKw($model, 'write', [[$id], $fields]);
    }

    /**
     * Delete records by IDs.
     */
    public function unlink(string $model, array $ids): bool
    {
        return (bool) $this->executeKw($model, 'unlink', [$ids]);
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Authenticate once per instance and cache the UID.
     */
    private function uid(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $result = $this->call('common', 'authenticate', [
            $this->database,
            $this->user,
            $this->password,
            [],
        ]);

        if (!$result || !is_int($result)) {
            throw new RuntimeException(
                'Odoo authentication failed. Check ODOO_URL, ODOO_DB, ODOO_USERNAME, ODOO_PASSWORD.'
            );
        }

        $this->uid = $result;
        return $this->uid;
    }

    /**
     * Call execute_kw with the authenticated UID.
     */
    private function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->call('object', 'execute_kw', [
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            $method,
            $args,
            $kwargs,
        ]);
    }

    /**
     * Low-level JSON-RPC call with retry and exponential backoff.
     *
     * @throws RuntimeException on unrecoverable errors or exhausted retries
     */
    private function call(string $service, string $method, array $args): mixed
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'id'      => ++$this->requestId,
            'params'  => [
                'service' => $service,
                'method'  => $method,
                'args'    => $args,
            ],
        ];

        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->http->post($this->jsonRpcUrl, ['json' => $payload]);
                $body     = json_decode((string) $response->getBody(), true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Odoo returned invalid JSON: ' . json_last_error_msg());
                }

                if (isset($body['error'])) {
                    $error = $body['error'];
                    $msg   = $error['data']['message'] ?? $error['message'] ?? 'Unknown Odoo RPC error';
                    // RPC-level errors (auth failures, field errors) are not retried.
                    throw new RuntimeException("Odoo RPC [{$error['code']}]: {$msg}");
                }

                return $body['result'] ?? null;

            } catch (ConnectException | RequestException $e) {
                $lastException = $e;

                if ($attempt < $this->maxRetries) {
                    $delay = (int) pow(2, $attempt); // 2 s → 4 s → 8 s
                    Log::channel('sync')->warning('Odoo HTTP error, retrying', [
                        'attempt'  => $attempt,
                        'max'      => $this->maxRetries,
                        'delay_s'  => $delay,
                        'service'  => $service,
                        'method'   => $method,
                        'error'    => $e->getMessage(),
                    ]);
                    sleep($delay);
                }
            }
        }

        throw new RuntimeException(
            "Odoo request failed after {$this->maxRetries} attempts: " . ($lastException?->getMessage() ?? 'unknown'),
            0,
            $lastException
        );
    }
}
