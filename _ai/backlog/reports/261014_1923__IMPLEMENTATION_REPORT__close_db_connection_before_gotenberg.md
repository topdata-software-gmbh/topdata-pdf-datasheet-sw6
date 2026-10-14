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
filesCreated: 1
filesModified: 3
filesDeleted: 0
tags: [shopware, gotenberg, pdf-datasheet, database, performance, bugfix]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report - Release the DB connection before the Gotenberg wait

## Summary

The storefront controller now closes the process-wide `Doctrine\DBAL\Connection` immediately
after Twig rendering and before the blocking Gotenberg HTTP call, releasing the MariaDB session
for the full 6–20 s render window. Verified live on the `focus` Shopware 6.7.10.2 instance:
during 12 parallel uncached datasheet renders, `Threads_connected` fell back to baseline while
the requests were still waiting on Gotenberg, and all 12 requests returned valid PDFs.

## Files Changed

| File | Change |
| --- | --- |
| `src/Controller/PdfDatasheetController.php` | Injected `Doctrine\DBAL\Connection`; guarded `close()` before `convertHtml()` |
| `src/Resources/config/services.xml` | Explicit `$connection` argument on the controller service |
| `AGENTS.md` | New gotcha bullet documenting the close/reconnect behavior |
| `_ai/backlog/reports/261014_1923__IMPLEMENTATION_REPORT__close_db_connection_before_gotenberg.md` | This report |

## Key Changes

- `src/Controller/PdfDatasheetController.php:121-127` — after the `?debug=1` early return and
  before the `convertHtml()` try block:

  ```php
  if ($this->connection->isConnected() && $this->connection->getTransactionNestingLevel() === 0) {
      $this->connection->close();
  }
  ```

- `src/Resources/config/services.xml:21` — `<argument type="service" key="$connection" id="Doctrine\DBAL\Connection"/>`
  (resolves to the kernel connection via `Kernel::getConnection()`, i.e. exactly the DAL's instance).

## Technical Decisions

- **Close the shared connection, not a separate one.** `Doctrine\DBAL\Connection` from the DI
  container *is* the process-wide connection used by the DAL (`Kernel::getConnection()`), so
  closing it releases the actual MariaDB session. A parallel dedicated connection would not
  reduce the connection count.
- **Transaction guard.** DBAL 4 `Connection::close()` silently resets `transactionNestingLevel`
  to 0 without rollback; the guard (`isConnected()` + nesting level 0) prevents silently
  dropping an open transaction.
- **Close lives in the controller, not `GotenbergClient`.** The HTTP client must not own DB
  lifecycle (SRP); the controller knows that nothing after rendering needs the DB.
- **Lazy reconnect is transparent.** Any later DB access (HTTP cache store, `kernel.terminate`
  listeners) reconnects on demand; session init commands are re-applied via `ATTR_INIT_COMMAND`.
  Cost is one handshake (~ms), dwarfed by the 6–20 s Gotenberg wait.

## Testing Notes

Environment: web runs in **dev** mode (`.env.local` sets `APP_ENV=dev`); commands executed via
`docker exec focus-www` (Shopware root `/www`), MariaDB via `docker exec focus-mariadb`.

1. **Syntax + container**
   - `php -l .../PdfDatasheetController.php` → `No syntax errors detected`
   - `xmllint --noout services.xml` → OK
   - `bin/console cache:clear` (dev) and `bin/console cache:clear --env=prod` → `[OK]`
   - `bin/console debug:container ...PdfDatasheetController` → arguments now include
     `Service(Doctrine\DBAL\Connection)`

2. **Baseline counters** — `Threads_connected = 5`, `Max_used_connections = 6`.

3. **Single render** (fresh URL after disk-cache clear) — `HTTP 200 in 2.2 s`,
   `PDF document, version 1.4, 3 page(s)`. The request's MariaDB session (id 59) was present at
   t=0 and **gone at t≈1 s while the request was still blocked in Gotenberg** (total 2.2 s) —
   the session is released before the wait.

4. **Lazy reconnect** — `curl https://focus.docker/` → `HTTP 200`; `var/log/dev.log` shows no
   new exceptions (only unrelated elasticsearch/deprecation entries).

5. **Concurrency (12 parallel uncached renders, distinct products)** —
   `Threads_connected` samples: 12 (t=1, includes the sampler connection), 11, 10, 6 (t=4),
   5 (t=5), 6, 6, 8, then steady 5 while requests were still running until t≈10.5 s. All 12
   returned `200` with valid PDFs (`Max_used_connections = 12`). Connections did **not**
   accumulate for the duration of the Gotenberg waits. (An earlier cold run showed individual
   renders take up to ~10 s; during that run too the count dropped back to baseline well before
   all requests had finished.)

6. **Regression checks**
   - `?debug=1` → `HTTP 200`, `Content-Type: text/html`, raw HTML body returned.
   - Cache-hit request → `HTTP 200` PDF without touching Gotenberg.

## Documentation Updates

`AGENTS.md` gained a "DB connection is closed before the Gotenberg call" bullet under
"Conventions and gotchas", warning future maintainers not to remove the close.

## Next Steps

The remaining crawler-load bottlenecks are still open and tracked separately:

- Bottleneck 2: external Google Fonts in `focus_shop.html.twig` (8–9 s render time)
  — plan `261014_2030__IMPLEMENTATION_PLAN__webfont_inlining.md`.
- Bottleneck 3: disk cache default `false` + ephemeral `%kernel.cache_dir%`.
- Bottleneck 4: concurrency throttling / HTTP 429 for Gotenberg saturation.
- Bottleneck 5: 5 s HTTP fallback per image in `PdfHelperExtension`.

Note: this fix frees the *MariaDB session*, not the *Apache/PHP worker* — worker starvation
under crawl load is still possible until bottleneck 4 is addressed.
