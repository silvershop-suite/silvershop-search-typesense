<?php

declare(strict_types=1);

namespace SilverShop\Search\Typesense;

use SilverShop\Page\Product;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * (Re)creates the Typesense collection, re-pushes synonyms and (re)indexes every published product. Run after install,
 * after changing the indexed fields, or to repair drift. Dropping and recreating the collection is the only
 * behavioural difference from the Meilisearch reindex task (Typesense needs the typed schema to exist up front).
 */
class ReindexSearchTask extends BuildTask
{
    protected static string $commandName = 'typesense-reindex';

    protected string $title = 'Typesense: reindex all published products';

    protected static string $description =
        'Recreates the Typesense collection, pushes synonyms and (re)indexes every published product.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $backend = Injector::inst()->get(TypesenseBackend::class);

        if (!$backend->isConfigured()) {
            $output->writeln('Typesense is not configured — set SS_TYPESENSE_HOST and SS_TYPESENSE_API_KEY.');
            return Command::FAILURE;
        }

        $backend->ensureCollection();
        $backend->pushSynonyms();
        $products = Versioned::get_by_stage(Product::class, Versioned::LIVE);
        $backend->index($products);

        $output->writeln('Indexed ' . $products->count() . ' published product(s) into Typesense.');

        return Command::SUCCESS;
    }
}
