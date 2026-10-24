<?php

namespace SilverShop\Search\Typesense\Tests;

use SilverShop\Page\Product;
use SilverShop\Search\Query\SearchQuery;
use SilverShop\Search\Typesense\TypesenseBackend;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\SiteConfig\SiteConfig;

class TypesenseBackendTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    public function testDocumentShapeStripsTagsCastsPriceAndStringifiesId(): void
    {
        $product = Product::create([
            'Title' => 'Red Shirt',
            'InternalItemID' => 'SKU1',
            'Model' => 'M1',
            'Content' => '<p>Nice shirt</p>',
            'BasePrice' => 19.95,
        ]);
        $product->write();

        $doc = (new TypesenseBackend())->document($product);

        $this->assertSame((string) $product->ID, $doc['id'], 'id is a string (Typesense primary key)');
        $this->assertSame('Red Shirt', $doc['Title']);
        $this->assertSame('SKU1', $doc['InternalItemID']);
        $this->assertSame('Nice shirt', $doc['Content']);
        $this->assertEqualsWithDelta(19.95, $doc['BasePrice'], 0.001);
    }

    public function testQueryReturnsProductsInTypesenseRankOrderWithFacets(): void
    {
        $a = Product::create(['Title' => 'A', 'BasePrice' => 1]);
        $a->write();
        $b = Product::create(['Title' => 'B', 'BasePrice' => 1]);
        $b->write();
        $c = Product::create(['Title' => 'C', 'BasePrice' => 1]);
        $c->write();

        // Typesense ranks C then A; B did not match.
        $backend = (new TypesenseBackend())->setClient(new FakeTypesenseClient([
            ['document' => ['id' => (string) $c->ID]],
            ['document' => ['id' => (string) $a->ID]],
        ]));
        $results = $backend->query(new SearchQuery(terms: 'anything'));

        $this->assertSame([$c->ID, $a->ID], $results->hits->column('ID'), 'rank order preserved');
        $this->assertSame(2, $results->total, 'B is excluded (not matched by Typesense)');
        $this->assertContains('price', array_column($results->facets, 'name'), 'facets come from the framework');
    }

    public function testBrowseWithoutTermsReturnsAllProductsWithoutEngineCall(): void
    {
        Product::create(['Title' => 'A', 'BasePrice' => 1])->write();
        Product::create(['Title' => 'B', 'BasePrice' => 1])->write();

        // No terms → no Typesense call; the full catalogue is faceted via the framework.
        $fake = new FakeTypesenseClient();
        $results = (new TypesenseBackend())->setClient($fake)->query(new SearchQuery());

        $this->assertSame(2, $results->total);
        $this->assertNotEmpty($results->facets);
        $this->assertSame([], $fake->lastSearchParams, 'no engine call for a termless browse');
    }

    public function testSearchSendsWeightedQueryByAndPerQueryTypoParams(): void
    {
        $a = Product::create(['Title' => 'A', 'BasePrice' => 1]);
        $a->write();

        $fake = new FakeTypesenseClient([['document' => ['id' => (string) $a->ID]]]);
        (new TypesenseBackend())->setClient($fake)->query(new SearchQuery(terms: 'x'));

        $this->assertSame('Title,InternalItemID,Model,Content', $fake->lastSearchParams['query_by']);
        $this->assertSame('4,3,2,1', $fake->lastSearchParams['query_by_weights'], 'descending field weights');
        $this->assertSame(2, $fake->lastSearchParams['num_typos']);
        $this->assertSame(4, $fake->lastSearchParams['min_len_1typo'], 'short-word typo tolerance');
        $this->assertSame(7, $fake->lastSearchParams['min_len_2typo']);
    }

    public function testIndexAndRemoveCallTheClient(): void
    {
        $product = Product::create(['Title' => 'P', 'BasePrice' => 1]);
        $product->write();

        $fake = new FakeTypesenseClient();
        $backend = (new TypesenseBackend())->setClient($fake);

        $backend->index(ArrayList::create([$product]));
        $this->assertCount(1, $fake->imported);
        $this->assertSame((string) $product->ID, $fake->imported[0]['id'], 'import payload carries the string id');

        $backend->remove((int) $product->ID);
        $this->assertSame([(string) $product->ID], $fake->deleted, 'delete carries the id');
    }

    public function testSynonymsParseIntoTypesenseMultiWayGroups(): void
    {
        $config = SiteConfig::current_site_config();
        $config->SearchSynonyms = "tv, television, telly\nlaptop, notebook\nignored";
        $config->write();

        $groups = (new TypesenseBackend())->synonyms();

        $this->assertCount(2, $groups, 'single-term lines are skipped');
        $this->assertSame(['tv', 'television', 'telly'], $groups[0]['synonyms']);
        $this->assertSame(['laptop', 'notebook'], $groups[1]['synonyms']);
    }

    public function testPushSynonymsBuildsOneSetAndAssociatesTheCollection(): void
    {
        $config = SiteConfig::current_site_config();
        $config->SearchSynonyms = "tv, television\nlaptop, notebook";
        $config->write();

        $fake = new FakeTypesenseClient();
        (new TypesenseBackend())->setClient($fake)->pushSynonyms();

        $this->assertArrayHasKey('products_synonyms', $fake->synonymSets, 'one named set holds all groups');
        $items = $fake->synonymSets['products_synonyms'];
        $this->assertSame(['id' => 'g0', 'synonyms' => ['tv', 'television']], $items[0]);
        $this->assertSame(['id' => 'g1', 'synonyms' => ['laptop', 'notebook']], $items[1]);
        $this->assertSame(['products_synonyms'], $fake->associatedSynonymSets, 'the collection points at the set');
    }

    public function testPushSynonymsWithNoneDeletesTheSetAndDeassociates(): void
    {
        $config = SiteConfig::current_site_config();
        $config->SearchSynonyms = '';
        $config->write();

        $fake = new FakeTypesenseClient();
        $fake->synonymSets['products_synonyms'] = [['id' => 'g0', 'synonyms' => ['x', 'y']]];
        (new TypesenseBackend())->setClient($fake)->pushSynonyms();

        $this->assertArrayNotHasKey('products_synonyms', $fake->synonymSets, 'the set is deleted when no groups remain');
        $this->assertSame([], $fake->associatedSynonymSets, 'the collection is de-associated');
    }

    public function testSuggestReturnsTopProductsInRankOrder(): void
    {
        $a = Product::create(['Title' => 'A', 'BasePrice' => 1]);
        $a->write();
        $b = Product::create(['Title' => 'B', 'BasePrice' => 1]);
        $b->write();

        $backend = (new TypesenseBackend())->setClient(new FakeTypesenseClient([
            ['document' => ['id' => (string) $b->ID]],
            ['document' => ['id' => (string) $a->ID]],
        ]));

        $this->assertSame([$b->ID, $a->ID], $backend->suggest('x')->column('ID'));
        $this->assertCount(0, $backend->suggest(''), 'empty terms = no suggestions');
    }

    public function testEnsureCollectionDropsThenPushesTypedSchema(): void
    {
        $fake = new FakeTypesenseClient();
        (new TypesenseBackend())->setClient($fake)->ensureCollection();

        $this->assertTrue($fake->dropped, 'reindex drops then recreates the collection');
        $this->assertNotNull($fake->schema);

        $names = array_column($fake->schema['fields'], 'name');
        $this->assertContains('Title', $names);
        $this->assertContains('InternalItemID', $names);
        $this->assertContains('Model', $names);
        $this->assertContains('Content', $names);
        $this->assertSame('CreatedTimestamp', $fake->schema['default_sorting_field']);
    }

    public function testNameAndCapabilities(): void
    {
        $backend = new TypesenseBackend();

        $this->assertSame('typesense', $backend->getName());
        $this->assertTrue($backend->supportsTypoTolerance());
        $this->assertTrue($backend->supportsSynonyms());
        $this->assertTrue($backend->supportsInstant());
    }
}
