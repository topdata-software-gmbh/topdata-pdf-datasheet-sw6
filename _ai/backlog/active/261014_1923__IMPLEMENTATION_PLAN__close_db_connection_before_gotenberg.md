---
filename: "_ai/backlog/active/261014_1923__IMPLEMENTATION_PLAN__close_db_connection_before_gotenberg.md"
title: "Close the shared DBAL connection before the blocking Gotenberg call to prevent MariaDB connection exhaustion"
createdAt: 2026-10-14 19:23
createdBy: opencode [deepseek-v4.1-flash]
updatedAt: 2026-10-14 19:23
updatedBy: opencode [deepseek-v4.1-flash]
status: draft
priority: high
tags: [shopware, gotenberg, pdf-datasheet, database, performance, bugfix]
project: "SW6.7 Plugin"
estimatedComplexity: simple
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan - Release the DB connection before the Gotenberg wait

## 1. Problem Statement

During a crawl (observed: `meta-externalagent/1.1`, 14/Oct/2026 13:26–13:29), many uncached
`/datasheet/{productNumber}/{slug}.pdf` requests were rendered in parallel. Each request:

1. queries MariaDB via the DAL (`loadProductByNumber()` — `PdfDatasheetController.php:59`),
2. renders Twig (no DB needed afterwards),
3. then **blocks for 6.4 s – 20.1 s** inside `GotenbergClient::convertHtml()`
   (`PdfDatasheetController.php:106`).

Observed Gotenberg latencies from the incident logs:

- `"latency_human":"6.421356411s"` — 200
- `"latency_human":"8.115606877s"` — 200
- `"latency_human":"9.54086544s"` — 200
- `"latency_human":"20.098138839s"` — **503 `context deadline exceeded`** (twice)

While PHP waits on Gotenberg, the process-wide DBAL connection stays open on the MariaDB
server in `Sleep` state for the whole render. Under crawler concurrency this piles up
connections until MariaDB reports **`SQLSTATE[HY000] [1040] Too many connections`**, which
takes down the rest of the shop (admin, queue workers, cron, storefront pages that still
need the DB).

**Goal of this plan:** close the shared DBAL connection immediately after the HTML has been
rendered and before the blocking Gotenberg HTTP call, so the MariaDB session is released
6–20 s earlier. Reconnection is lazy and transparent, so any later DB access in the same
request (HTTP cache store, terminate listeners) simply reconnects.

**Scope note (important):** this frees the *MariaDB session*, not the *Apache/PHP worker*.
It stops DB connection exhaustion but does not by itself prevent worker starvation or
Gotenberg 503s under heavy crawl load. Throttling, font removal, and persistent caching are
tracked as separate bottlenecks and are explicitly out of scope here.

## 2. Implementation Notes

### Environment (verified against the live setup)

- Shopware root: `/topdata/clones/focus/vol/www` — Shopware **6.7.10.2**, Doctrine DBAL **4.4.3**.
- Web server: Apache **prefork + mod_php**, `MaxRequestWorkers 150` (`focus-www` container).
- MariaDB (`focus-mariadb`): `max_connections = 151` (default).
- Gotenberg (`focus-gotenberg`): image `gotenberg/gotenberg:8`, no CPU limit configured locally.

### Key facts verified in vendor code

- The DI service `Doctrine\DBAL\Connection` is registered in
  `vendor/shopware/core/Framework/DependencyInjection/services.xml:70` with factory
  `Shopware\Core\Kernel::getConnection()` — i.e. **exactly the process-wide connection used
  by the DAL**. Injecting/autowiring it is safe and standard (core wires it the same way).
- DBAL 4.4.3 `Connection::close()` (`vendor/doctrine/dbal/src/Connection.php:412-416`) sets
  `_conn = null` and **silently resets `transactionNestingLevel = 0`** — hence the transaction
  guard in Phase 1.
- `isConnected()` exists (`Connection.php:340`), `getTransactionNestingLevel()` exists
  (`Connection.php:920`).
- If read replicas are ever configured (`DATABASE_REPLICA_0_URL`), the connection is a
  `PrimaryReadReplicaConnection` (subclass); its `close()` closes primary **and** replica
  (`vendor/doctrine/dbal/src/Connections/PrimaryReadReplicaConnection.php:295`). The
  `Doctrine\DBAL\Connection` type hint stays correct.

### Relevant files

| File | Role |
| --- | --- |
| `src/Controller/PdfDatasheetController.php` | Add `Connection` dependency + guarded close before `convertHtml()` |
| `src/Resources/config/services.xml` | Explicitly wire `Doctrine\DBAL\Connection` into the controller |
| `AGENTS.md` | Document the non-obvious close/reconnect behavior |
| `_ai/backlog/reports/…` | Final implementation report |

### Commands (run from `/topdata/clones/focus/vol/www` unless noted)

```bash
# Syntax check
php -l custom/plugins/topdata-pdf-datasheet-sw6/src/Controller/PdfDatasheetController.php

# Rebuild container / caches after DI + controller change
bin/console cache:clear

# Clear disk cache so requests actually render (soft-depends on TopdataFoundationSW6)
bin/console topdata:pdf-datasheet:cache-clear

# MariaDB live diagnostics
docker exec focus-mariadb mysql -uroot -p11111 -N \
  -e "SHOW STATUS LIKE 'Threads_connected'; SHOW STATUS LIKE 'Max_used_connections';"

# Render one datasheet and time it
curl -k -s -o /tmp/ds.pdf -w '%{http_code} %{time_total}\n' \
  'https://focus.docker/datasheet/5222/oki-toner-kit-cyan-43872307.pdf'
```

## 3. Implementation Phases

### Phase 1: Guarded connection close in the controller

**Objective:** release the shared MariaDB connection right before the blocking Gotenberg
call, with a transaction guard.

#### [MODIFY] `src/Controller/PdfDatasheetController.php`

1. Add the import:

```php
use Doctrine\DBAL\Connection;
```

2. Extend the constructor (new parameter; keep existing order otherwise):

```php
    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly GotenbergClient $gotenbergClient,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection,
        private readonly string $cacheDir
    ) {}
```

3. Insert the close between the `?debug` early return (current lines 99–103) and the
   `try { … convertHtml … }` block (current line 105):

```php
        // The DAL connection is process-wide and stays open in Sleep during the
        // Gotenberg wait. Nothing after this point needs the DB; any later query
        // reconnects lazily. Guard required: DBAL 4 close() silently resets the
        // transaction nesting level without rollback.
        if ($this->connection->isConnected() && $this->connection->getTransactionNestingLevel() === 0) {
            $this->connection->close();
        }

        try {
            $pdfContent = $this->gotenbergClient->convertHtml($gotenbergUrl, $htmlContent, $headerHtml, $footerHtml);
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
```

**Deliverable:** controller closes the connection exactly once, only on the render path,
only when no transaction is active.

**Do NOT:**

- close before/inside the `renderView()` calls (Twig can still trigger lazy entity loads),
- close in the `?debug=1` branch (no Gotenberg wait there),
- close in the disk-cache-hit branch (no DB use on that path),
- put the close inside `GotenbergClient` (would violate SRP — the HTTP client must not own
  DB lifecycle).

### Phase 2: DI wiring

**Objective:** make the dependency explicit and deterministic.

#### [MODIFY] `src/Resources/config/services.xml`

`defaults autowire="true"` would already resolve `Doctrine\DBAL\Connection`, but core style
is explicit. Add the argument to the controller service:

```xml
        <service id="Topdata\TopdataPdfDatasheetSW6\Controller\PdfDatasheetController" public="true">
            <argument type="service" key="$productRepository" id="sales_channel.product.repository"/>
            <argument type="service" key="$connection" id="Doctrine\DBAL\Connection"/>
            <argument type="string" key="$cacheDir">%kernel.cache_dir%</argument>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>
```

**Deliverable:** container builds, controller receives the kernel connection instance.

### Phase 3: Documentation

**Objective:** future maintainers must not remove the close as "dead code".

#### [MODIFY] `AGENTS.md` (plugin root)

Add one bullet under "Conventions and gotchas":

> **DB connection is closed before the Gotenberg call** (`PdfDatasheetController.php`, right
> before `convertHtml()`). This releases the process-wide `Doctrine\DBAL\Connection` during
> the 6–20 s blocking render and prevents MariaDB `Too many connections` under crawler load.
> It is guarded by `isConnected()` + `getTransactionNestingLevel() === 0`; any DB query after
> the close transparently reconnects. Do not remove.

### Phase 4: Verification

**Objective:** prove the DB session is released during the Gotenberg wait and nothing breaks.

1. **Syntax + container**
   ```bash
   php -l custom/plugins/topdata-pdf-datasheet-sw6/src/Controller/PdfDatasheetController.php
   bin/console cache:clear
   ```
2. **Baseline counters**
   ```bash
   docker exec focus-mariadb mysql -uroot -p11111 -N \
     -e "SHOW STATUS LIKE 'Threads_connected'; SHOW STATUS LIKE 'Max_used_connections';"
   ```
3. **Single render, observe the wait window** — clear the disk cache first, then:
   ```bash
   bin/console topdata:pdf-datasheet:cache-clear
   curl -k -s -o /tmp/ds.pdf -w '%{http_code} %{time_total}\n' \
     'https://focus.docker/datasheet/5222/oki-toner-kit-cyan-43872307.pdf' &
   sleep 3
   docker exec focus-mariadb mysql -uroot -p11111 -N \
     -e "SELECT ID, USER, COMMAND, TIME FROM information_schema.PROCESSLIST ORDER BY TIME DESC;"
   wait
   ```
   Expected: while the curl request is blocked in Gotenberg, there is **no long-running
   `Sleep` connection attributable to the web worker**; the PDF still returns 200
   (`file /tmp/ds.pdf` → `PDF document`).
4. **Lazy reconnect works** — after the request, load a normal DB-backed page:
   ```bash
   curl -k -s -o /dev/null -w '%{http_code}\n' 'https://focus.docker/'
   ```
   Expected: `200` (no exceptions in `var/log/`).
5. **Concurrency check** — 10–12 parallel uncached datasheet requests (distinct product
   numbers), watching `Threads_connected` during the run. Expected: connections do not
   accumulate for the duration of the Gotenberg waits. Gotenberg 503s under saturation are
   expected and are out of scope (bottleneck 4).
6. **Regression checks** — `?debug=1` still returns HTML; a cache-hit request still returns
   the cached PDF without touching Gotenberg.

### Phase 5: Implementation report

#### [NEW FILE] `_ai/backlog/reports/261014_1923__IMPLEMENTATION_REPORT__close_db_connection_before_gotenberg.md`

```yaml
---
filename: "_ai/backlog/reports/261014_1923__IMPLEMENTATION_REPORT__close_db_connection_before_gotenberg.md"
title: "Report: Close the shared DBAL connection before the blocking Gotenberg call"
createdAt: 2026-10-14 19:23
createdBy: opencode [deepseek-v4.1-flash]
updatedAt: 2026-10-14 19:23
updatedBy: opencode [deepseek-v4.1-flash]
planFile: "_ai/backlog/active/261014_1923__IMPLEMENTATION_PLAN__close_db_connection_before_gotenberg.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 0
filesModified: 3
filesDeleted: 0
tags: [shopware, gotenberg, pdf-datasheet, database, performance, bugfix]
documentType: IMPLEMENTATION_REPORT
---
```

Report body must cover: Summary, Files Changed, Key Changes, Technical Decisions
(shared-connection close vs. separate connection; transaction guard), Testing Notes
(commands from Phase 4 with observed outputs), Documentation Updates, Next Steps
(bottlenecks 2–4 still open).

## 4. Edge Cases and Risks

| Case | Behavior |
| --- | --- |
| Active transaction at close time | Guard skips close (DBAL 4 would silently drop it otherwise) |
| Read-replica connection class | `PrimaryReadReplicaConnection::close()` closes primary + replica |
| DB access after close (HTTP cache store, `kernel.terminate` listeners) | Lazy reconnect; session init commands re-applied via `ATTR_INIT_COMMAND`; costs one handshake (~ms) |
| Gotenberg throws after close | Catch path returns 500; connection already released; no DB needed |
| Product not found / empty Gotenberg URL | Exception/return happens before the close; unchanged behavior |
| `?debug=1` | Returns before the close; unchanged behavior |

## 5. Out of Scope (tracked separately)

- Bottleneck 2: external Google Fonts in `focus_shop.html.twig` (8–9 s render time).
- Bottleneck 3: disk cache default `false` + ephemeral `%kernel.cache_dir%`.
- Bottleneck 4: concurrency throttling / HTTP 429 for Gotenberg saturation.
- Bottleneck 5: 5 s HTTP fallback per image in `PdfHelperExtension`.
