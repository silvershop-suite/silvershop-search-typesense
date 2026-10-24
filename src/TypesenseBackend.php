<?php

declare(strict_types=1);

namespace SilverShop\Search\Typesense;

use SilverShop\Page\Product;
use SilverShop\Search\Backend\SearchBackend;
use SilverShop\Search\Facet\FacetApplier;
use SilverShop\Search\Query\SearchQuery;
use SilverShop\Search\Query\SearchResults;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Typesense-backed {@link SearchBackend}: typo-tolerant, relevance-ranked product search.
 *
 * Products are indexed (by {@link index()} / the reindex task / {@link ProductSyncExtension}); a query lets Typesense
 * do the typo-tolerant text match + relevance ranking, then reuses silvershop-suite/search's {@link FacetApplier} to
 * apply the selected facets and count their options over the matched set — so every registered facet works, unchanged,
 * with no per-facet engine mapping. Results come back in Typesense's rank order (for relevance) and the SearchPage
 * controller paginates them (no change needed in silvershop-suite/search). Native Typesense facet_counts is a later
 * optimisation.
 *
 * A near-exact port of {@link \SilverShop\Search\Meilisearch\MeilisearchBackend}; the differences are mechanical REST
 * idioms absorbed in {@link TypesenseClient} (typed collection up front, per-query query_by/typo params, `found`,
 * `hits[].document`, synonyms as a resource).
 */
class TypesenseBackend implements SearchBackend
{
    use Configurable;
    use Injectable;

    /** Typesense caps `per_page` at 250; larger windows are gathered by paging. */
    private const int MAX_PER_PAGE = 250;

    private static string $collection_name = 'products';

    /** Max ranked hits fetched per query (the controller paginates this window). */
    private static int $result_window = 1000;

    /** Default number of suggestions returned by the instant-search endpoint. */
    private static int $instant_limit = 8;

    /**
     * Document fields searched for free text, highest-weighted first. Becomes the per-query `query_by` list and a
     * descending `query_by_weights` (first field highest). There is no implicit "all fields" in Typesense, so a field
     * not listed here is never text-matched.
     *
     * @var array<int, string>
     */
    private static array $query_by = ['Title', 'InternalItemID', 'Model', 'Content'];

    /**
     * Per-query typo tolerance. Typesense's own defaults already match the house intent (one typo from 4 letters, two
     * from 7), so a 4-letter term like "iPad" tolerates a one-character typo such as "iPod" out of the box — no index
     * setting needed. Config-overridable per key.
     *
     * @var array<string, int>
     */
    private static array $typo = [
        'num_typos' => 2,
        'min_len_1typo' => 4,
        'min_len_2typo' => 7,
    ];

    private ?TypesenseClient $client = null;

    public function setClient(TypesenseClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getName(): string
    {
        return 'typesense';
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Typesense');
    }

    public function isConfigured(): bool
    {
        return (string) Environment::getEnv('SS_TYPESENSE_HOST') !== ''
            && (string) Environment::getEnv('SS_TYPESENSE_API_KEY') !== '';
    }

    public function query(SearchQuery $query): SearchResults
    {
        // Typesense matches the free text (typo-tolerant, ranked); facet filtering + counts reuse the framework.
        if ($query->hasTerms()) {
            $rankedIds = $this->matchIds($query->terms);
            $base = Product::get()->filter('ID', $rankedIds ?: [0]);
        } else {
            $rankedIds = [];
            $base = Product::get();
        }

        $applied = FacetApplier::apply($base, $query);
        $products = $applied['products'];

        // Keep Typesense's relevance order for a text search with no explicit sort; otherwise sort in the ORM.
        $hits = ($query->hasTerms() && $query->sort === '')
            ? $this->rankOrder($products, $rankedIds)
            : $this->applySort($products, $query->sort);

        return new SearchResults($hits, $hits->count(), $applied['facets']);
    }

    /**
     * Top matches for instant-search / autocomplete: a quick Typesense text match (no facets), products in rank order.
     */
    public function suggest(string $terms, ?int $limit = null): SS_List
    {
        if (trim($terms) === '') {
            return ArrayList::create();
        }

        $limit = $limit ?? (int) self::config()->get('instant_limit');
        $limit = max(1, min(self::MAX_PER_PAGE, $limit));
        $response = $this->client()->search($this->searchParams($terms, $limit));
        $ids = $this->idsFrom($response);

        return $this->rankOrder(Product::get()->filter('ID', $ids ?: [0]), $ids);
    }

    public function index(SS_List $products): void
    {
        $documents = [];
        foreach ($products as $product) {
            if ($product instanceof Product) {
                $documents[] = $this->document($product);
            }
        }
        $this->client()->importDocuments($documents);
    }

    public function remove(int $productID): void
    {
        $this->client()->deleteDocument((string) $productID);
    }

    /**
     * (Re)create the collection with the typed field schema. Run by the reindex task: drops then recreates so a
     * field-set change takes effect. This is Typesense's analogue of Meilisearch's settings push.
     */
    public function ensureCollection(): void
    {
        $this->client()->dropCollection();
        $this->client()->ensureCollection($this->collectionSchema());
    }

    /**
     * Push the CMS-managed synonym groups to Typesense as a single named synonym set (one item per group) and point
     * the collection at it. No reindex needed to tune synonyms. With no groups, de-associate and delete the set.
     */
    public function pushSynonyms(): void
    {
        $client = $this->client();
        $setName = (string) self::config()->get('collection_name') . '_synonyms';
        $groups = $this->synonyms();

        if (!$groups) {
            $client->associateSynonymSets([]);
            $client->deleteSynonymSet($setName);
            return;
        }

        $items = [];
        foreach ($groups as $i => $group) {
            $items[] = ['id' => 'g' . $i, 'synonyms' => $group['synonyms']];
        }
        $client->upsertSynonymSet($setName, $items);
        $client->associateSynonymSets([$setName]);
    }

    /**
     * CMS-managed synonyms (SiteConfig.SearchSynonyms) as Typesense multi-way groups. One group per line,
     * comma-separated; a group needs at least two terms (single-term lines are skipped).
     *
     * @return array<int, array{synonyms: array<int, string>}>
     */
    public function synonyms(): array
    {
        $raw = (string) SiteConfig::current_site_config()->SearchSynonyms;
        $groups = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $terms = array_values(array_filter(
                array_map('trim', explode(',', $line)),
                static fn (string $term): bool => $term !== ''
            ));
            if (count($terms) < 2) {
                continue;
            }
            $groups[] = ['synonyms' => array_map('mb_strtolower', $terms)];
        }

        return $groups;
    }

    public function supportsTypoTolerance(): bool
    {
        return true;
    }

    public function supportsSynonyms(): bool
    {
        return true;
    }

    public function supportsInstant(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Product $product): array
    {
        return [
            // Typesense's primary key is always a string field named `id`.
            'id' => (string) $product->ID,
            'Title' => (string) $product->Title,
            'InternalItemID' => (string) $product->InternalItemID,
            'Model' => (string) $product->Model,
            'Content' => trim(strip_tags((string) $product->Content)),
            'BasePrice' => (float) $product->BasePrice,
            'CreatedTimestamp' => (int) strtotime((string) $product->Created),
        ];
    }

    /**
     * The typed collection schema pushed by {@link ensureCollection()}: the query_by fields as strings, the sortable
     * numeric fields, and CreatedTimestamp as the default sorting field. (`id` is implicit in Typesense.)
     *
     * @return array<string, mixed>
     */
    public function collectionSchema(): array
    {
        $fields = [];
        foreach (array_values((array) self::config()->get('query_by')) as $name) {
            $fields[] = ['name' => (string) $name, 'type' => 'string', 'optional' => true];
        }
        $fields[] = ['name' => 'BasePrice', 'type' => 'float', 'optional' => true, 'sort' => true];
        $fields[] = ['name' => 'CreatedTimestamp', 'type' => 'int64', 'sort' => true];

        return [
            'name' => (string) self::config()->get('collection_name'),
            'fields' => $fields,
            'default_sorting_field' => 'CreatedTimestamp',
        ];
    }

    /**
     * Common search params: the free text, the weighted query_by fields and the per-query typo tolerance.
     *
     * @return array<string, scalar>
     */
    private function searchParams(string $terms, int $perPage, int $page = 1): array
    {
        $fields = array_values(array_map('strval', (array) self::config()->get('query_by')));
        $weights = $fields ? range(count($fields), 1) : [];
        $typo = (array) self::config()->get('typo');

        return [
            'q' => $terms,
            'query_by' => implode(',', $fields),
            'query_by_weights' => implode(',', $weights),
            'num_typos' => (int) ($typo['num_typos'] ?? 2),
            'min_len_1typo' => (int) ($typo['min_len_1typo'] ?? 4),
            'min_len_2typo' => (int) ($typo['min_len_2typo'] ?? 7),
            'per_page' => $perPage,
            'page' => $page,
            'include_fields' => 'id',
        ];
    }

    /**
     * Typesense-ranked product ids for the free-text terms. Paged at Typesense's 250-hit cap up to `result_window`.
     *
     * @return array<int, int>
     */
    private function matchIds(string $terms): array
    {
        $window = max(1, (int) self::config()->get('result_window'));
        $perPage = min(self::MAX_PER_PAGE, $window);
        $ids = [];
        $page = 1;
        do {
            $response = $this->client()->search($this->searchParams($terms, $perPage, $page));
            $hits = is_array($response['hits'] ?? null) ? $response['hits'] : [];
            $ids = array_merge($ids, $this->idsFrom($response));
            $found = (int) ($response['found'] ?? 0);
            $page++;
        } while (count($ids) < $window && count($hits) >= $perPage && count($ids) < $found);

        return array_slice(array_values(array_unique($ids)), 0, $window);
    }

    /**
     * Pull the ranked product ids out of a Typesense search response (hits under `hits[].document.id`).
     *
     * @param array<string, mixed> $response
     * @return array<int, int>
     */
    private function idsFrom(array $response): array
    {
        $hits = is_array($response['hits'] ?? null) ? $response['hits'] : [];

        return array_values(array_filter(array_map(
            static fn ($hit): int => (int) (is_array($hit) ? ($hit['document']['id'] ?? 0) : 0),
            $hits
        )));
    }

    private function applySort(DataList $products, string $sort): DataList
    {
        return match ($sort) {
            'price_asc' => $products->sort('BasePrice', 'ASC'),
            'price_desc' => $products->sort('BasePrice', 'DESC'),
            'newest' => $products->sort('Created', 'DESC'),
            'title' => $products->sort('Title', 'ASC'),
            default => $products,
        };
    }

    /**
     * Order the facet-filtered products by the Typesense rank.
     *
     * @param array<int, int> $rankedIds
     */
    private function rankOrder(DataList $products, array $rankedIds): ArrayList
    {
        $byID = [];
        foreach ($products as $product) {
            $byID[(int) $product->ID] = $product;
        }
        $list = ArrayList::create();
        foreach ($rankedIds as $id) {
            if (isset($byID[$id])) {
                $list->push($byID[$id]);
            }
        }

        return $list;
    }

    private function client(): TypesenseClient
    {
        return $this->client ??= TypesenseClient::create(
            (string) Environment::getEnv('SS_TYPESENSE_HOST'),
            (int) (Environment::getEnv('SS_TYPESENSE_PORT') ?: 8108),
            (string) (Environment::getEnv('SS_TYPESENSE_PROTOCOL') ?: 'http'),
            (string) Environment::getEnv('SS_TYPESENSE_API_KEY'),
            (string) self::config()->get('collection_name')
        );
    }
}
