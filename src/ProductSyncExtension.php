<?php

declare(strict_types=1);

namespace SilverShop\Search\Typesense;

use SilverShop\Search\Backend\BackendRegistry;
use SilverStripe\Core\Extension;
use SilverStripe\Model\List\ArrayList;

/**
 * Keeps the Typesense collection in step with the product catalogue: (re)index on publish, drop on unpublish/delete.
 * Only acts when Typesense is the active, configured backend, and never lets a search-engine hiccup break a save.
 *
 * @extends Extension<\SilverShop\Page\Product>
 */
class ProductSyncExtension extends Extension
{
    protected function onAfterPublish($original = null): void
    {
        if ($backend = $this->activeBackend()) {
            $this->guard(fn () => $backend->index(ArrayList::create([$this->getOwner()])));
        }
    }

    protected function onAfterUnpublish(): void
    {
        $this->removeFromIndex();
    }

    protected function onAfterDelete(): void
    {
        $this->removeFromIndex();
    }

    private function removeFromIndex(): void
    {
        if ($backend = $this->activeBackend()) {
            $this->guard(fn () => $backend->remove((int) $this->getOwner()->ID));
        }
    }

    private function activeBackend(): ?TypesenseBackend
    {
        $backend = (new BackendRegistry())->getActive();

        return ($backend instanceof TypesenseBackend && $backend->isConfigured()) ? $backend : null;
    }

    private function guard(callable $sync): void
    {
        try {
            $sync();
        } catch (\Throwable) {
            // A search-engine outage must never block publishing; the reindex task can repair drift later.
        }
    }
}
