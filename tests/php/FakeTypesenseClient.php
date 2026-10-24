<?php

namespace SilverShop\Search\Typesense\Tests;

use SilverShop\Search\Typesense\TypesenseClient;

/**
 * In-memory stand-in for {@link TypesenseClient} — records calls and returns canned search hits in Typesense's shape,
 * so the backend can be tested without a running Typesense. Deliberately does not call the parent constructor (no HTTP
 * client).
 */
class FakeTypesenseClient extends TypesenseClient
{
    /** @var array<int, array<string, mixed>> */
    public array $imported = [];

    /** @var array<int, int|string> */
    public array $deleted = [];

    /** @var array<string, mixed>|null */
    public ?array $schema = null;

    public bool $dropped = false;

    /** @var array<string, array<int, string>> */
    public array $synonyms = [];

    /** @var array<string, scalar> */
    public array $lastSearchParams = [];

    /**
     * @param array<int, array<string, mixed>> $searchHits
     */
    public function __construct(private array $searchHits = [])
    {
    }

    public function search(array $params): array
    {
        $this->lastSearchParams = $params;

        return [
            'hits' => $this->searchHits,
            'found' => count($this->searchHits),
            'facet_counts' => [],
        ];
    }

    public function importDocuments(array $documents): void
    {
        $this->imported = array_merge($this->imported, $documents);
    }

    public function upsertDocument(array $document): void
    {
        $this->imported[] = $document;
    }

    public function deleteDocument(int|string $id): void
    {
        $this->deleted[] = $id;
    }

    public function ensureCollection(array $schema): void
    {
        $this->schema = $schema;
    }

    public function dropCollection(): void
    {
        $this->dropped = true;
    }

    public function upsertSynonym(string $id, array $terms): void
    {
        $this->synonyms[$id] = array_values($terms);
    }

    public function deleteSynonym(string $id): void
    {
        unset($this->synonyms[$id]);
    }

    public function health(): bool
    {
        return true;
    }
}
