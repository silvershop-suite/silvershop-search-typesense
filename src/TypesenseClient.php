<?php

declare(strict_types=1);

namespace SilverShop\Search\Typesense;

use GuzzleHttp\Client;
use SilverStripe\Core\Injector\Injectable;

/**
 * Thin Typesense REST client (Guzzle). Deliberately minimal — just the few endpoints the backend needs — to avoid the
 * official typesense/typesense-php SDK's php-http/discovery dependency, which the SilverStripe stack disables.
 *
 * Typesense idioms absorbed here (and nowhere else): the `X-TYPESENSE-API-KEY` header, the `host:port` split (default
 * port 8108), an explicit typed collection schema created up front, JSONL bulk import (Content-Type text/plain),
 * search as a GET with query-string params, and synonyms as a separate resource.
 */
class TypesenseClient
{
    use Injectable;

    private Client $http;

    public function __construct(
        string $host,
        int $port,
        string $protocol,
        string $key,
        private string $collection
    ) {
        $protocol = $protocol !== '' ? $protocol : 'http';
        $port = $port > 0 ? $port : 8108;
        $this->http = new Client([
            'base_uri' => sprintf('%s://%s:%d/', $protocol, trim($host, '/'), $port),
            'headers' => $key !== '' ? ['X-TYPESENSE-API-KEY' => $key] : [],
            'timeout' => 10,
        ]);
    }

    /**
     * Search a collection. Typesense takes query-string params on a GET (scalar values only), and returns hits under
     * `hits[].document`, the total under `found`, and facets under `facet_counts`.
     *
     * @param array<string, scalar> $params
     * @return array<string, mixed>
     */
    public function search(array $params): array
    {
        $response = $this->http->request('GET', "collections/{$this->collection}/documents/search", [
            'query' => $params,
        ]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /**
     * Bulk upsert documents via the JSONL import endpoint (one JSON object per line, Content-Type text/plain).
     *
     * @param array<int, array<string, mixed>> $documents
     */
    public function importDocuments(array $documents): void
    {
        if (!$documents) {
            return;
        }
        $lines = array_map(static fn (array $doc): string => (string) json_encode($doc), $documents);
        $this->http->request('POST', "collections/{$this->collection}/documents/import", [
            'query' => ['action' => 'upsert'],
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => implode("\n", $lines),
        ]);
    }

    /**
     * Upsert a single document.
     *
     * @param array<string, mixed> $document
     */
    public function upsertDocument(array $document): void
    {
        $this->http->request('POST', "collections/{$this->collection}/documents", [
            'query' => ['action' => 'upsert'],
            'json' => $document,
        ]);
    }

    public function deleteDocument(int|string $id): void
    {
        $this->http->request('DELETE', "collections/{$this->collection}/documents/{$id}", [
            'query' => ['ignore_not_found' => 'true'],
        ]);
    }

    /**
     * Create the collection with the given typed schema if it does not already exist. Unlike Meilisearch, Typesense
     * needs the collection to exist with the right field types before any import.
     *
     * @param array<string, mixed> $schema
     */
    public function ensureCollection(array $schema): void
    {
        try {
            $this->http->request('GET', "collections/{$this->collection}");
            return;
        } catch (\Throwable) {
            // Not found → create it below.
        }
        $this->json('POST', 'collections', $schema);
    }

    /**
     * Drop the collection (permanent). Guarded so a missing collection is a no-op — the reindex task drops then
     * recreates to pick up a field-set change.
     */
    public function dropCollection(): void
    {
        try {
            $this->http->request('DELETE', "collections/{$this->collection}");
        } catch (\Throwable) {
            // Already absent — nothing to drop.
        }
    }

    /**
     * Upsert a multi-way synonym group (a separate Typesense resource, not an index setting).
     *
     * @param array<int, string> $terms
     */
    public function upsertSynonym(string $id, array $terms): void
    {
        $this->json('PUT', "collections/{$this->collection}/synonyms/{$id}", ['synonyms' => array_values($terms)]);
    }

    public function deleteSynonym(string $id): void
    {
        try {
            $this->http->request('DELETE', "collections/{$this->collection}/synonyms/{$id}");
        } catch (\Throwable) {
            // Already absent.
        }
    }

    public function health(): bool
    {
        try {
            $this->http->request('GET', 'health');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed> $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $uri, array $body): array
    {
        $response = $this->http->request($method, $uri, ['json' => $body]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }
}
