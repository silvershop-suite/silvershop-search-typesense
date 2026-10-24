# SilverShop Search — Typesense

A [Typesense](https://typesense.org/) engine adapter for
[`silvershop-suite/search`](https://github.com/silvershop-suite/silvershop-search): typo-tolerant, relevance-ranked
product search, as a drop-in replacement for the built-in database backend.

## Requirements

- `silvershop-suite/search` `^1` (the search framework)
- A reachable **Typesense** server (self-hosted — a single binary)

## Running Typesense

Typesense is a single prebuilt binary. Grab it from [typesense.org/downloads](https://typesense.org/downloads/), or
install the package / run the container:

```bash
# Debian/Ubuntu package (installs a systemd unit + config at /etc/typesense/typesense-server.ini)
curl -O https://dl.typesense.org/releases/30.0/typesense-server-30.0-amd64.deb
sudo apt install ./typesense-server-30.0-amd64.deb

# …or Docker
docker run -p 8108:8108 -v /var/lib/typesense:/data typesense/typesense:30.0 \
  --data-dir /data --api-key=change-me-to-a-long-random-key

# …or the raw binary (quick local test)
./typesense-server --data-dir=/tmp/typesense-data --api-key=change-me --listen-port=8108
```

Default API port **8108**; auth is **API-key only** (the package auto-generates one into
`/etc/typesense/typesense-server.ini`). Health check: `GET /health` → `{"ok":true}`.

Keep Typesense bound to localhost (behind your app), persist `/data` and the API key, and size the box for RAM —
Typesense holds its index in memory, so a large catalogue needs proportional RAM.

> **Licence note.** The Typesense **server** is GPL-3.0. That is fine for self-hosting: you run the binary and this
> BSD-3 module talks to it over HTTP — nothing links or redistributes the server. Do not bundle the server binary in a
> client deliverable; install it on the box.

## Installation

```bash
composer require silvershop-suite/search-typesense
```

Point it at your Typesense and make it the active backend:

```bash
# .env
SS_TYPESENSE_HOST="127.0.0.1"
SS_TYPESENSE_PORT="8108"
SS_TYPESENSE_PROTOCOL="http"
SS_TYPESENSE_API_KEY="your-api-key"
```

```yaml
# app/_config/search.yml
SilverShop\Search\Backend\BackendRegistry:
  active_backend: 'typesense'
```

Then build the collection and index:

```bash
sake tasks:typesense-reindex
```

Run `dev/build?flush=1` first so the config + extension load.

## How it works

- Products are indexed on **publish** and removed on **unpublish / delete** (`ProductSyncExtension`), and the
  **`typesense-reindex`** task does a full (re)build: it drops and recreates the collection with an explicit typed
  schema, re-pushes synonyms, then bulk-imports every published product (JSONL `action=upsert`).
- A search runs against Typesense and returns the matching products **in Typesense's relevance order**; the SearchPage
  controller paginates them as usual — no change to `silvershop-suite/search`.
- Indexed fields: `Title`, `InternalItemID` (SKU), `Model`, `Content` (searchable, weighted in that order) and
  `BasePrice` / `CreatedTimestamp` (sortable; `CreatedTimestamp` is the default sorting field). You get typo-tolerance,
  relevance ranking and instant-search speed out of the box — the gaps the DB backend has.
- A search-engine outage never blocks publishing (sync is guarded); run the reindex task to repair any drift.

## Facets

All registered facets (category / price / brand / rating / colour / attributes / stock) work unchanged with the
Typesense backend: Typesense does the typo-tolerant text match + ranking, then `silvershop-suite/search`'s facet
framework applies the selected filters and counts options over the matched products. No per-facet configuration needed.

## Relevance & synonyms

**Field weighting** — free-text matches rank by field importance: `Title`, then SKU (`InternalItemID`), `Model`,
`Content`. The field list becomes Typesense's per-query `query_by` plus a descending `query_by_weights`. Reorder via
config:

```yaml
SilverShop\Search\Typesense\TypesenseBackend:
  query_by: [Title, InternalItemID, Model, Content]
```

**Synonyms** — edit them in the CMS at **Settings → Shop → Search synonyms**: one group per line, comma-separated
(e.g. `tv, television, telly`). Every term in a group matches the others. Saving pushes each group to Typesense
immediately — all groups are pushed as one named `synonym_sets` resource and associated with the collection, so they
apply to every search with no per-query param and no reindex needed. (Synonyms require **Typesense ≥ 29**, which
replaced the old per-collection synonyms API with top-level `/synonym_sets`.)

**Typo tolerance** — set per query. Typesense's own defaults already match the house intent (one typo from 4-letter
terms, two from 7), so a 4-letter query like `iPad` reaches `iPod` out of the box. Tune per key via config:

```yaml
SilverShop\Search\Typesense\TypesenseBackend:
  typo:
    num_typos: 2
    min_len_1typo: 4
    min_len_2typo: 7
```

## Instant search (autocomplete)

A JSON endpoint for typeahead lives at `/<your search page>/instant?q=…` (e.g. `/search/instant?q=tshi`). It returns
the top matches fast, for a theme to render a suggestions dropdown:

```json
{"query":"tshi","results":[{"title":"T-Shirt","link":"/shop/apparel/t-shirt/","price":"$25.00","image":"/…"}]}
```

Limit via config:

```yaml
SilverShop\Search\Typesense\TypesenseBackend:
  instant_limit: 8
```

## Roadmap

- Native Typesense `facet_counts` (a performance optimisation for very large catalogs), via the shared facet-framework
  lift in `silvershop-suite/search`.

## License

BSD-3-Clause. See [LICENSE](LICENSE).

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
