<?php

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Neo4jHttpTransport
{
    /**
     * @param array<string, mixed> $statement
     * @return array{success: bool, rows: array<int, array<string, mixed>>, error: ?string}
     */
    public function executeStatement(array $statement): array
    {
        return $this->sendImplicitQuery($statement);
    }

    /**
     * @param array<int, array<string, mixed>> $statements
     * @return array{success: bool, rows: array<int, array<string, mixed>>, error: ?string}
     */
    public function executeTransaction(array $statements): array
    {
        if ($statements === []) {
            return [
                'success' => true,
                'rows' => [],
                'error' => null,
            ];
        }

        return $this->sendExplicitQueryApiTransaction($statements);
    }

    protected function sendImplicitQuery(array $statement): array
    {
        $statement = $this->normalizeStatement($statement);

        try {
            $response = $this->request()
                ->withBody($this->encodeBody([
                    'statement' => $statement['statement'],
                    'parameters' => $statement['parameters'],
                ]), 'application/json')
                ->post($this->queryApiUrl());

            return $this->mapQueryApiResponse($response);
        } catch (\Throwable $e) {
            Log::warning('Neo4j Query API request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'rows' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $statements
     * @return array{success: bool, rows: array<int, array<string, mixed>>, error: ?string}
     */
    protected function sendExplicitQueryApiTransaction(array $statements): array
    {
        $statements = array_values(array_map(fn (array $statement) => $this->normalizeStatement($statement), $statements));

        $rows = [];
        $affinity = null;
        $transactionId = null;

        try {
            $open = $this->request()
                ->withBody($this->encodeBody([
                    'statement' => $statements[0]['statement'],
                    'parameters' => $statements[0]['parameters'],
                ]), 'application/json')
                ->post($this->queryApiTransactionOpenUrl());

            $openMapped = $this->mapQueryApiResponse($open);
            if (!$openMapped['success']) {
                return $openMapped;
            }

            $rows = array_merge($rows, $openMapped['rows']);
            $transactionId = $open->json('transaction.id');
            $affinity = $open->header('neo4j-cluster-affinity');

            if (!is_string($transactionId) || $transactionId === '') {
                return [
                    'success' => false,
                    'rows' => [],
                    'error' => 'Neo4j Query API transaction did not return a transaction id.',
                ];
            }

            for ($i = 1; $i < count($statements); $i++) {
                $response = $this->requestWithAffinity($affinity)
                    ->withBody($this->encodeBody([
                        'statement' => $statements[$i]['statement'],
                        'parameters' => $statements[$i]['parameters'],
                    ]), 'application/json')
                    ->post($this->queryApiTransactionUrl($transactionId));

                $mapped = $this->mapQueryApiResponse($response);
                if (!$mapped['success']) {
                    $this->rollbackTransaction($transactionId, $affinity);

                    return $mapped;
                }

                $rows = array_merge($rows, $mapped['rows']);
            }

            $commit = $this->requestWithAffinity($affinity)
                ->withBody($this->encodeBody(['parameters' => (object) []]), 'application/json')
                ->post($this->queryApiTransactionCommitUrl($transactionId));

            $commitMapped = $this->mapQueryApiResponse($commit, allowEmptyData: true);
            if (!$commitMapped['success']) {
                return $commitMapped;
            }

            return [
                'success' => true,
                'rows' => $rows,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            if (is_string($transactionId) && $transactionId !== '') {
                $this->rollbackTransaction($transactionId, $affinity);
            }

            Log::warning('Neo4j Query API transaction failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'rows' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function rollbackTransaction(string $transactionId, ?string $affinity): void
    {
        try {
            $this->requestWithAffinity($affinity)
                ->delete($this->queryApiTransactionUrl($transactionId));
        } catch (\Throwable $e) {
            Log::debug('Neo4j Query API transaction rollback failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{success: bool, rows: array<int, array<string, mixed>>, error: ?string}
     */
    protected function mapQueryApiResponse(Response $response, bool $allowEmptyData = false): array
    {
        if (!in_array($response->status(), [200, 202], true)) {
            return [
                'success' => false,
                'rows' => [],
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
            ];
        }

        $errors = $response->json('errors', []);
        if (is_array($errors) && $errors !== []) {
            return [
                'success' => false,
                'rows' => [],
                'error' => json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $data = $response->json('data');
        if ($data === null) {
            return [
                'success' => $allowEmptyData,
                'rows' => [],
                'error' => $allowEmptyData ? null : 'Neo4j Query API response did not include data.',
            ];
        }

        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $values = is_array($data['values'] ?? null) ? $data['values'] : [];
        $rows = [];

        foreach ($values as $valueRow) {
            if (!is_array($valueRow)) {
                continue;
            }

            $mapped = [];
            foreach ($valueRow as $index => $value) {
                $field = $fields[$index] ?? null;
                if (!is_string($field) || $field === '') {
                    continue;
                }

                $mapped[$field] = $this->normalizeGraphValue($value);
            }

            if ($mapped !== []) {
                $rows[] = $mapped;
            }
        }

        return [
            'success' => true,
            'rows' => $rows,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $statement
     * @return array<string, mixed>
     */
    protected function normalizeStatement(array $statement): array
    {
        $statement['statement'] = preg_replace('/\s+/', ' ', trim((string) ($statement['statement'] ?? '')));
        $statement['parameters'] = $this->normalizeParameterValue($statement['parameters'] ?? []);

        return $statement;
    }

    protected function normalizeParameterValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($value === []) {
                return (object) [];
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeParameterValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    protected function normalizeGraphValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('properties', $value) && count($value) === 1) {
            return $this->normalizeGraphValue($value['properties']);
        }

        if (array_key_exists('properties', $value) && array_key_exists('labels', $value)) {
            return $this->normalizeGraphValue($value['properties']);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeGraphValue($item);
        }

        return $value;
    }

    protected function encodeBody(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected function request(): PendingRequest
    {
        $request = Http::timeout((int) config('ai-engine.graph.timeout', 10))
            ->acceptJson()
            ->asJson();

        $username = config('ai-engine.graph.neo4j.username');
        $password = config('ai-engine.graph.neo4j.password');

        if (is_string($username) && $username !== '' && is_string($password) && $password !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        return $request;
    }

    protected function requestWithAffinity(?string $affinity): PendingRequest
    {
        $request = $this->request();

        if (is_string($affinity) && $affinity !== '') {
            $request = $request->withHeaders([
                'neo4j-cluster-affinity' => $affinity,
            ]);
        }

        return $request;
    }

    protected function queryApiUrl(): string
    {
        return $this->baseUrl() . '/db/' . $this->database() . '/query/v2';
    }

    protected function queryApiTransactionOpenUrl(): string
    {
        return $this->queryApiUrl() . '/tx';
    }

    protected function queryApiTransactionUrl(string $transactionId): string
    {
        return $this->queryApiTransactionOpenUrl() . '/' . $transactionId;
    }

    protected function queryApiTransactionCommitUrl(string $transactionId): string
    {
        return $this->queryApiTransactionUrl($transactionId) . '/commit';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('ai-engine.graph.neo4j.url', 'http://localhost:7474'), '/');
    }

    protected function database(): string
    {
        return trim((string) config('ai-engine.graph.neo4j.database', 'neo4j'));
    }

}
