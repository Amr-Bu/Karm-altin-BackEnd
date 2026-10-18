# Current follow-up — authorized audit of karem_altin

Date: 2026-10-12. This section supersedes the execution counts, database-access restriction and verdict explanation in the **historical first audit** retained below. The user now permits using karem_altin, subject to backup, no destructive operations, no code fixes, no real printing, and no mutations whose reversal cannot be guaranteed.

## A. Backup and environment

A full database SQL dump was created **before** the test run using XAMPP mysqldump with single-transaction, quick, skip-lock-tables, routines, events, triggers, hex-blob and utf8mb4 options. No dump SQL was executed against any database.

- Backup: `C:\Users\amrbu\AppData\Local\Temp\karem_altin_audit_20261012_182623\karem_altin_full.sql`.
- Size: **42,780 bytes**.
- SHA-256: `d5347066f0a1a1d493b60004cfd905a52a262666e54b61e47bf63fd770480960`.
- mysqldump exited **0**, with completion marker present.
- All **21 base-table definitions** are present; all source tables use **InnoDB**.
- Both recipe triggers are present. An initial verification pattern incorrectly expected quoted trigger names; inspecting the dump and using an optional-quote pattern confirmed both. No trigger is missing.
- Source metadata lists **0 views, 0 routines, 0 events**. Corresponding dump options were enabled.
- The dump contains schema, data, indexes/constraints, trigger definitions and AUTO_INCREMENT values for this database. It is a database backup, not a server-wide account/grant backup.
- **Restore verification limit:** export completion and object coverage were checked; no restore rehearsal was performed. Full restorability has therefore not been demonstrated by an actual import. No database was created or dropped, and no restore was attempted on karem_altin.
- The backup remains outside the repository in the user's temporary directory. It was not published or committed. Credentials and table contents are not included in this report.

PHP 8.2.12 / Laravel 12.68.0 / PHPUnit 11.5.56 / MariaDB 10.4.32 remain the inspected environment. The existing MySQL PHPUnit configuration was used with process-only DB_DATABASE=karem_altin and DB_CONNECTION=mysql; no .env/config file was edited. The selected root-page test uses array sessions/cache, sync queue, array mail and a temporary compiled-view directory beside the backup. The database-free example does not access the application database.

## B. Current results

| Kind | PASS | FAIL | BLOCKED |
|---|---:|---:|---:|
| Existing PHPUnit cases | 2 | 0 | 169 |
| Existing standalone integration runners | 0 | 0 | 2 |
| Read-only current-data consistency checks | 8 | 2 | 0 |
| **Total execution/check units** | **10** | **2** | **171** |

The SQL checks are read-only audit diagnostics executed inline, **not newly added automated test files** and not API integration tests. Results apply to the observed snapshot only; absence of bad rows does not prove that all workflows prevent them. Backup/hash diagnostics are not inflated into extra PASS counts. The earlier unit-test run is not counted again as a separate unique case.

## C. Executed existing tests

Command:

```text
php vendor/bin/phpunit -c phpunit.mysql.xml --do-not-cache-result --filter ExampleTest --testdox
```

Result: **OK (2 tests, 2 assertions)**, exit 0, 2.186 seconds, 30 MB.

| Test | Status | Evidence / effects |
|---|---|---|
| Tests\\Unit\\ExampleTest::test_that_true_is_true | PASS | Existing assertTrue(true); no database activity |
| Tests\\Feature\\ExampleTest::test_the_application_returns_a_successful_response | PASS | Existing GET / returned 200; routes/web.php only renders welcome view; no fixture setup or business API calls; compiled views redirected outside project |

## D. Current database consistency checks

All queries below ran through a PDO **READ ONLY** transaction with a consistent snapshot; it was then rolled back. No SQL errors occurred. FAIL means returned data violated the stated consistency condition, not that a mutating API failure was reproduced.

| ID | Condition (expected zero violations) | Status | Actual violations |
|---|---|---|---:|
| R01 | No negative current inventory | PASS | 0 |
| R02 | No independent inventory row for PRODUCED_PRODUCT | FAIL | 3 |
| R03 | At most one active order per table | PASS | 0 |
| R04 | OCCUPIED corresponds to an active order | PASS | 0 |
| R05 | No order is both PAID and CANCELLED | PASS | 0 |
| R06 | Item subtotal equals rounded quantity × saved unit price | PASS | 0 |
| R07 | Order item/chair/grand totals agree | PASS | 0 |
| R08 | Current stock equals recorded IN minus OUT | FAIL | 1 |
| R09 | Produced parent, physical component, positive recipe quantity | PASS | 0 |
| R10 | Every paid order has paid_at | PASS | 0 |

### D01 / R02 — Produced products have independent stock rows

**Severity: MEDIUM. Executed read-only finding.**

Expected: no inventory_stock rows for PRODUCED_PRODUCT. Actual: **3 rows** — stock_id/product_id **7/7, 8/8, 9/9**, each with current_quantity **0.000**.

Location: `inventory_stock` joined to `products`; related suspected creation path `database/seeders/InventoryStockSeeder.php:13-17`, which selects all stock-tracked products, including produced products seeded by `database/seeders/ProductSeeder.php:22-32`.

This confirms the data-state aspect of historical S06. The seeder was not executed and historical provenance is not proven. No row was removed or changed.

### D02 / R08 — Current quantity disagrees with recorded inventory movements

**Severity: HIGH for inventory accuracy. Executed read-only finding; root cause unproven.**

Location: `inventory_stock.id=12`, `product_id=12`, and that product's `stock_transactions`.

| Current quantity | Recorded IN minus OUT | Difference (current minus ledger) |
|---:|---:|---:|
| 0.000 | 19.000 | -19.000 |

Expected: current quantity agrees with complete recorded movements from the system's initial zero balance. Actual: one product differs by **19.000**. This pre-existed the selected tests: the same result appeared in the before and after snapshots.

Related audit locations: `app/Services/InventoryService.php:53-80,121-159` maintains stock and movements transactionally; `database/seeders/InventoryStockSeeder.php:15-17` derives initial stock from the ledger. These are investigation entry points, **not attribution of the defect**. A historical manual change, unrecorded opening balance or prior incomplete write could explain it; current evidence does not establish which. No automatic reconciliation or repair was attempted.

### Exact diagnostic queries


**R01**

```sql
SELECT COUNT(*) FROM inventory_stock WHERE current_quantity<0;
```

**R02**

```sql
SELECT COUNT(*) FROM inventory_stock s JOIN products p ON p.id=s.product_id WHERE p.item_type='PRODUCED_PRODUCT';
```

**R03**

```sql
SELECT COUNT(*) FROM (SELECT table_id FROM orders WHERE order_status NOT IN ('CLOSED','CANCELLED') GROUP BY table_id HAVING COUNT(*)>1) q;
```

**R04**

```sql
SELECT COUNT(*) FROM restaurant_tables t WHERE (t.status='OCCUPIED') <> EXISTS(SELECT 1 FROM orders o WHERE o.table_id=t.id AND o.order_status NOT IN ('CLOSED','CANCELLED'));
```

**R05**

```sql
SELECT COUNT(*) FROM orders WHERE payment_status='PAID' AND order_status='CANCELLED';
```

**R06**

```sql
SELECT COUNT(*) FROM order_items WHERE subtotal<>ROUND(quantity*unit_price,2);
```

**R07**

```sql
SELECT COUNT(*) FROM orders o WHERE items_subtotal<>COALESCE((SELECT SUM(subtotal) FROM order_items i WHERE i.order_id=o.id AND i.status='ACTIVE'),0) OR chairs_total<>ROUND(chairs_count*chair_price,2) OR total_amount<>items_subtotal+chairs_total;
```

**R08**

```sql
SELECT COUNT(*) FROM inventory_stock s WHERE current_quantity<>COALESCE((SELECT SUM(CASE WHEN direction='IN' THEN quantity ELSE -quantity END) FROM stock_transactions m WHERE m.product_id=s.product_id),0);
```

**R09**

```sql
SELECT COUNT(*) FROM product_ingredients i JOIN products p ON p.id=i.product_id JOIN products c ON c.id=i.raw_material_id WHERE p.item_type<>'PRODUCED_PRODUCT' OR c.item_type NOT IN ('RAW_MATERIAL','PURCHASED_PRODUCT') OR i.quantity_required<=0;
```

**R10**

```sql
SELECT COUNT(*) FROM orders WHERE payment_status='PAID' AND paid_at IS NULL;
```

## E. Safety classification of existing tests

DatabaseTransactions in the installed framework starts and rolls back the configured connections (default **[null]**) at `vendor/laravel/framework/src/Illuminate/Foundation/Testing/DatabaseTransactions.php:16-36,48-52`. The feature classes do not extend connectionsToTransact. No DDL, TRUNCATE, RefreshDatabase use, connection switching or explicit outer commit was found in the six business feature classes. The RefreshDatabase line in Feature/ExampleTest is a comment, not an active trait.

Nevertheless, all **169** business cases insert auto-incremented fixtures in setUp. Rolled-back inserts can consume AUTO_INCREMENT numbers that ROLLBACK does not restore. This is a non-reversible metadata side effect, not deletion/corruption of existing rows, but the user's current rule blocks tests where complete reversal cannot be guaranteed. Resetting those counters would require prohibited schema operations and could conflict with concurrent writers. No probe insert was used to demonstrate this on development.

| Suite / runner | Cases | Transaction coverage and other risks | Decision |
|---|---:|---|---|
| CreateEmployeeTest | 30 | DatabaseTransactions, default connection; setUp lines 27-30 inserts user/token; no printer/DDL path found; auto-increment allocations remain a reversal limitation | BLOCKED |
| ProductsRecipesTest | 32 | DatabaseTransactions, default connection; shared fixture; existing-trigger DML rejection checks at line 282 do not create/drop triggers; stock/order writes and counter allocations | BLOCKED |
| ManagementModulesTest | 20 | DatabaseTransactions, shared fixture; receipt rejection path, printer/table/setting changes; default connection; counter allocations | BLOCKED |
| OrderWorkflowTest | 15 | DatabaseTransactions; nested service transactions and GET_LOCK; receipt failure test uses 127.0.0.1:1, success test creates localhost listener at line 259, local-agent test does not send; not real printers, but fixture writes/counters persist as a reversal limitation | BLOCKED |
| RemainingModulesTest | 55 | DatabaseTransactions and shared fixture; last-admin test at line 266 temporarily changes roles of users other than fixture admin, including development accounts; tokens/stock/expense writes; counter allocations | BLOCKED |
| OrdersReportsTest | 17 | DatabaseTransactions and shared fixture; retry tests use transport mocks at lines 161/187/201; these are single-process tests and do not prove independent-connection concurrency; counter allocations | BLOCKED |
| tests/run-postman.php | 1 runner | Creates a separate DB at line 32, migrates line 45, multiple HTTP processes/connections and curl_multi, drops separate DB line 187; support scripts depend on this harness, not independent safe tests | BLOCKED; not retargeted to karem_altin |
| tests/verify-recipe-migration.php | 1 runner | Creates separate DB line 24, named recipe_test connection, migrations and trigger up/down, drops DB line 77 | BLOCKED; not run against current database |

Shared `tests/Support/OrderFixtures.php:19-36` inserts users/table/printers/departments/products/recipe/stock; line 26 disables existing receipt printers, and line 28 overwrites/creates chair_price. These writes are expected to roll back on the same InnoDB connection, but the trait alone neither protects other connections nor reverses external I/O or auto-increment allocation.

No real printer was contacted. Existing localhost fixtures/mocks are distinguishable from real hardware; they were not treated as real-print tests, but remained blocked for their database side effects. No mail, queue worker, HTTP integration server or background test process was launched.

The complete **169 blocked PHPUnit case names** are the business-feature entries in the retained discovery manifest below; every such entry retains BLOCKED in this follow-up. Both ExampleTest entries now PASS as shown above, superseding the historical root-page NOT RUN. No existing tests were edited to remove fixtures or turn them into read-only tests.

## F. Changes and rollback verification

Before and after the selected tests, every row of all **21 tables** was read in a consistent, read-only snapshot. Each row was hashed without exposing its contents, row hashes were sorted, and a SHA-256 fingerprint per table was calculated. Row counts and AUTO_INCREMENT metadata were compared as well.

**Result: zero differing table fingerprints, zero row-count changes, zero AUTO_INCREMENT changes.**

| Table | Rows before / after | AUTO_INCREMENT before / after |
|---|---:|---:|
| cache | 2 / 2 | NULL / NULL |
| cache_locks | 0 / 0 | NULL / NULL |
| categories | 4 / 4 | 424 / 424 |
| departments | 3 / 3 | 1820 / 1820 |
| expenses | 5 / 5 | 169 / 169 |
| failed_jobs | 0 / 0 | 1 / 1 |
| inventory_stock | 12 / 12 | 3635 / 3635 |
| jobs | 0 / 0 | 1 / 1 |
| job_batches | 0 / 0 | NULL / NULL |
| migrations | 20 / 20 | 21 / 21 |
| orders | 6 / 6 | 408 / 408 |
| order_items | 13 / 13 | 753 / 753 |
| personal_access_tokens | 4 / 4 | 1245 / 1245 |
| printers | 4 / 4 | 1842 / 1842 |
| print_jobs | 7 / 7 | 280 / 280 |
| products | 12 / 12 | 4674 / 4674 |
| product_ingredients | 12 / 12 | 957 / 957 |
| restaurant_tables | 5 / 5 | 1112 / 1112 |
| settings | 1 / 1 | 14 / 14 |
| stock_transactions | 26 / 26 | 428 / 428 |
| users | 4 / 4 | 2040 / 2040 |

No test data was inserted, modified or deleted by this follow-up. There were no business writes to roll back; only the read-only diagnostic transactions were closed with ROLLBACK. No backup restoration was needed or attempted. Before/after equality demonstrates no observed persisted difference during the test interval; it cannot establish that unrelated concurrent actors never performed and reversed their own writes.

Files created outside the project: the authorized SQL backup and runtime compiled views in its temporary directory. Inside the project, only this report is changed. No application source, routes, migrations, seeders, existing tests, Postman files, dependency files or .env/configuration were modified; no fixes, DDL, migrate:fresh, db:wipe, RefreshDatabase or counter resets were performed.

## G. Findings to retain for owner review

- **D01 (MEDIUM):** three independent produced-product stock rows, now observed directly; historical S06 explains a suspected path.
- **D02 (HIGH):** product 12 current stock 0.000 versus movement balance 19.000, newly observed.
- Historical S01-S05 and S07-S11 remain **STATIC REVIEW ONLY**; this run did not reproduce those workflow/security/printing issues.
- Historical S12 still describes the implicit MySQL test-target configuration. Use of karem_altin is now explicitly authorized, so the old “no permission to use development” reason is superseded. The remaining blockers are complete reversal and unverified restore rehearsal, not lack of permission to connect.

## H. Current readiness assessment

**INSUFFICIENT TEST EVIDENCE**, with **two observed data-integrity failures**.

Two existing safe tests passed, and eight snapshot consistency checks passed. No mutating API lifecycle, rollback, fault-injection or true concurrency case ran. A backup does not itself make irreversible effects transactional; completing those tests on this database would require relaxing the exact-reversal constraint or using an already isolated environment. No permission is inferred to reset counters, restore over development, change tests, or repair the two observed inconsistencies.

## I. Unchanged-project confirmation

Only `docs/testing/integration-test-report.md` was updated in the project. The SQL backup was created outside it as explicitly requested. The 21-table comparison found no persisted data or counter changes. No printer command was sent and no repair was applied.

---

# Historical first audit — retained evidence

**The sections below describe the earlier audit before permission to use karem_altin. Their counts, “not run” root example, metadata-only scope, and unconfirmed S06 data state are historical. Use the current follow-up above for latest results.**


# Laravel backend integration testing and audit

Audit date: 2026-10-12 (Asia/Damascus). Repository: `D:\laravel_projects\karem-altin-backend`.
Mode: **STRICT NO-FIX**. This report is the only deliverable changed.

## A. Test environment

| Item | Observed evidence |
|---|---|
| PHP | 8.2.12 |
| Laravel | 12.68.0 |
| PHPUnit | 11.5.56 |
| Database server | 10.4.32-MariaDB; session time_zone = SYSTEM |
| Development environment | APP_ENV=local, DB_CONNECTION=mysql, host 127.0.0.1:3306, database karem_altin |
| Testing configuration | phpunit.mysql.xml / phpunit.orders.xml set testing/mysql but omit DB_DATABASE; no .env.testing or dedicated database override found |
| Default PHPUnit configuration | SQLite :memory:; not used as a substitute for MySQL integration verification |
| Database isolation | No verified, populated, dedicated project test database available. The generic XAMPP test schema has no project tables and was not repurposed |
| Existing PHPUnit cases | 171 expanded cases: 169 business feature cases, 1 root-page feature example, 1 database-free unit example |
| API routes | 50 entries discovered with php artisan route:list --path=api --json; GET/HEAD counted as one route entry |
| Postman | 69 requests: 25 raw-body, 5 form-data, 39 without bodies |
| Safe database test execution | **No**. Business feature tests and database-creating integration runners were not executed |
| External printing | No HTTP business requests, printer connections, physical printing, or integration servers were started |

Read-only diagnostics used PHP/framework version output, route discovery, PHPUnit test discovery, source inspection, and inline PDO metadata queries. Database inspection was restricted to SELECT VERSION(), session time zone, SHOW DATABASES, and information_schema metadata for tables, triggers, and constraints. No application records were read to establish business outcomes and no database writes were issued.

The inspected MariaDB metadata contains recipe insert/update guards accepting RAW_MATERIAL and PURCHASED_PRODUCT components and requiring a PRODUCED_PRODUCT parent. Generated active-table uniqueness and schema constraints were inspected alongside migrations. This verifies metadata, **not** transactional behavior or successful execution of trigger rejection cases.

All six business feature classes use DatabaseTransactions. They still perform writes, including fixture setup; transactions do not make the development database an authorized test target. The two standalone runners create, migrate, and later drop temporary databases. Their isolation mechanism is incompatible with this task's explicit prohibition on creating databases/changing schemas.

### Execution record

- Read-only route discovery: `php artisan route:list --path=api --json`; exit 0, 50 entries.
- Discovery only: `php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --list-tests tests`; 171 expanded cases. Discovery is not test execution.
- Executed: `php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit/ExampleTest.php`; exit 0, **OK (1 test, 1 assertion)**, 0.547 seconds, 10 MB.
- Not executed: MySQL suites, the default SQLite suite, Postman runner, recipe migration runner, business API calls, concurrency workloads, transport fault injection, migrations, or seeders.
- A guessed request-file lookup and a Windows wildcard search had diagnostic lookup errors; corrected file discovery located FinancialReportRequest.php. These are not application test failures.

## B. Summary

| Result | Count |
|---|---:|
| PASS | 1 |
| FAIL | 0 |
| BLOCKED | 171 |
| NOT RUN | 1 |
| STATIC REVIEW ONLY | 12 |

Counting basis: 171 discovered PHPUnit cases = 1 PASS + 169 BLOCKED + 1 NOT RUN. Two additional standalone integration runners are BLOCKED, giving 171 blocked execution units and **172 blocked/not-run units combined**. The 12 static entries are the numbered suspected findings/configuration/operational risks in section E; they are not failed tests. Endpoint inventory and requirement analysis below are supporting static coverage, not additional counted test cases. Individual assertions inside blocked runners are not counted as executed or separately discovered cases.

**No business API scenario passed or failed through execution during this audit.** The single passing example only verifies PHPUnit can execute a trivial assertion. Historical test results or existing reports are not current-audit results.

## C. Successful tests

| Test ID | Module | Scenario | Status | Evidence |
|---|---|---|---|---|
| U01 | Test tooling only | Tests\\Unit\\ExampleTest::test_that_true_is_true | PASS | Existing assertTrue(true); command above returned OK (1 test, 1 assertion). No Laravel application or database bootstrapped |

This is the complete list of successful executed scenarios.

## D. Failed tests

| Test ID | Module | Scenario | Expected | Actual | Location | Severity |
|---|---|---|---|---|---|---|
| None | — | No executed test failed | — | 0 failures | — | — |

No runtime business failure, response error, exception trace, or concurrency failure was captured. Findings below must not be presented as reproduced failures.

## E. Suspected bugs discovered through static inspection

Every entry is **STATIC REVIEW ONLY**. Reproduction steps are proposals for a separately authorized isolated environment; they were not executed. “Actual” below means inspected code behavior/inferred outcome, not a captured HTTP result. No exact runtime error is available unless an existing code message is explicitly quoted.

### S01 — Orders authorization: write and receipt paths bypass the read ownership boundary

- **Severity / status:** HIGH / STATIC REVIEW ONLY.
- **Location:** `routes/api.php:70,73`; `app/Http/Requests/UpdateOrderRequest.php:9`; `app/Http/Controllers/Api/OrderController.php:57,72`; `app/Services/OrderUpdateService.php:13`; `app/Services/ReceiptPrintingService.php:17`; `app/Services/OrderQueryService.php:34`.
- **Expected:** Employee B must not modify or retrieve employee A's order if the ownership policy used by order reads also applies to these operations.
- **Suggested reproduction:** Using a second active employee and an existing editable order belonging to A, PATCH /api/orders/{id} with chairs_count=1; separately POST /api/orders/{id}/print-receipt using an existing fake transport.
- **Inspected behavior / inferred outcome:** Read details explicitly checks employee_id, but neither write service checks the actor. The update returns full order details; receipt also returns the order and payload and can attempt a network send. This is an inferred authorization inconsistency, not an executed exploit. Confirm whether shared-table editing/printing is intentionally permitted; unrestricted receipt access still bypasses the read restriction.
- **Suspected root cause:** Authorization is confined to OrderQueryService rather than consistently enforced for order-bound operations.
- **Runtime error evidence:** None; not executed.

### S02 — Approved order edits use the current recipe/tracking flags instead of consumed quantities

- **Severity / status:** HIGH / STATIC REVIEW ONLY.
- **Location:** `app/Services/OrderUpdateService.php:20,44`; `app/Services/InventoryService.php:85`; `app/Services/RecipeService.php:29,40`; `app/Services/OrderCancellationService.php:20`.
- **Expected:** Removing an approved item must return the quantities actually consumed for that item; recipe edits must not manufacture or lose stock.
- **Suggested reproduction:** Approve one meal with chicken requirement 0.250, change its recipe requirement to 0.500, then remove that meal. Also try deleting a component or disabling stock tracking before removal.
- **Inspected behavior / inferred outcome:** Both before and after requirements are computed from the newly loaded recipe. Removing the example item is inferred to return 0.500 after consuming only 0.250. Changed component IDs/tracking flags can leave original consumption unreturned; an empty tracked recipe instead rejects editing. Whole-order cancellation uses movement history, but cannot undo an excessive return already made by an item edit.
- **Suspected root cause:** Order item updates have no consumed recipe snapshot/per-item ledger basis; recalculation assumes an immutable recipe.
- **Runtime error evidence:** None; not executed.

### S03 — Replacing items sharing an ingredient can report a false stock shortage

- **Severity / status:** MEDIUM / STATIC REVIEW ONLY.
- **Location:** `app/Services/OrderUpdateService.php:44,69`; `app/Services/InventoryService.php:121,128,148`.
- **Expected:** An atomic replacement with equal ingredient demand should need no additional physical stock.
- **Suggested reproduction:** Approve product A consuming the last 1.000 of ingredient X. In one update remove A and add product B requiring the same 1.000 X.
- **Inspected behavior / inferred outcome:** The update accumulates IN=1.000 and OUT=1.000. apply checks OUT against available=0 before accounting for IN and is inferred to reject with InsufficientStockException/HTTP 422 although the resulting stock would be zero. The enclosing transaction should roll back; no partial writes are claimed.
- **Suspected root cause:** Shortage preflight checks gross outgoing demand rather than the net change for shared ingredients.
- **Runtime error evidence:** None; not executed.

### S04 — Empty produced recipe can bypass validation when stock tracking is disabled

- **Severity / status:** MEDIUM / STATIC REVIEW ONLY.
- **Location:** `app/Services/InventoryService.php:85,87,93`; `app/Services/OrderDataService.php:39`; `app/Services/OrderApprovalService.php:24`.
- **Expected:** Scenario N requires an invalid produced recipe not to bypass recipe consumption validation.
- **Suggested reproduction:** Create an active sellable PRODUCED_PRODUCT with no ingredients and is_stock_tracked=false; create and approve an order for it.
- **Inspected behavior / inferred outcome:** Create validates product type/active/price, not recipe. Approval requirements immediately returns an empty array for an untracked product before checking recipe validity. A tracked empty recipe instead throws InvalidOrderStateException at line 93. This is a mismatch with unconditional scenario N; an intentional exemption for untracked products would need to be specified.
- **Suspected root cause:** Tracking flag short-circuits recipe validation.
- **Runtime error evidence:** None; not executed.

### S05 — Approved item note-only changes create no department modification job

- **Severity / status:** MEDIUM / STATIC REVIEW ONLY.
- **Location:** `app/Services/OrderUpdateService.php:29`; `app/Services/DepartmentPrintingService.php:26`.
- **Expected:** If changed preparation notes must reach the kitchen, an approved note-only edit must create an appropriate notification.
- **Suggested reproduction:** Approve a meal, then submit the same item quantity with a changed note, e.g. a changed preparation instruction.
- **Inspected behavior / inferred outcome:** The item note is saved and the loop immediately continues before adding a printing change. Inferred result: response contains the new note, but existing kitchen payload remains unchanged and no new job is created. This is conditional on the expected note-notification policy, not an explicit executed failure.
- **Suspected root cause:** Printing changes are driven only by quantity comparison.
- **Runtime error evidence:** None; not executed.

### S06 — Inventory seeder creates independent stock for produced products

- **Severity / status:** MEDIUM / STATIC REVIEW ONLY.
- **Location:** `database/seeders/InventoryStockSeeder.php:13`; `database/seeders/ProductSeeder.php:22,32`.
- **Expected:** PRODUCED_PRODUCT must not have independent inventory_stock; physical inventory belongs to raw/purchased products.
- **Suggested reproduction:** In a separately authorized disposable environment, run existing seeders and inspect inventory_stock joined to products where item_type=PRODUCED_PRODUCT. Do not run against development.
- **Inspected behavior / inferred outcome:** InventoryStockSeeder includes every is_stock_tracked product. ProductSeeder marks its three produced products tracked, so their independent stock rows are inferred to be inserted. ProductService's API creation and inventory listing use physical types, producing inconsistent seeded and API-created data. No development rows were queried to claim this is already present.
- **Suspected root cause:** Seeder selection uses the tracking flag without filtering physical product types.
- **Runtime error evidence:** None; not executed.

### S07 — Receipt persistence errors after transmission can enable a duplicate print

- **Severity / status:** HIGH / STATIC REVIEW ONLY.
- **Location:** `app/Services/ReceiptPrintingService.php:44`; `app/Services/PrintJobService.php:33,74`.
- **Expected:** A database error after successful transmission must not misclassify a possibly printed receipt as safely retryable.
- **Suggested reproduction:** With an existing fake transport and a separately authorized fault-injection test, let send succeed, fail the PRINTED update once, allow the catch's FAILED update to succeed, then retry.
- **Inspected behavior / inferred outcome:** The same try block encloses send and the PRINTED database update. A persistence exception enters the transport-failure catch and attempts to set FAILED, which the retry endpoint accepts. Inferred outcome: a second physical transmission is possible; the attempt count can also reflect in-memory dirty attributes from the failed save. This is conditional on the failure sequence; no printer or database fault was induced.
- **Suspected root cause:** Transport and persistence exceptions share a catch that treats both as failed transmission.
- **Runtime error evidence:** None; not executed.

### S08 — Department jobs have no delivery path in the inspected backend; interrupted retries can remain pending

- **Severity / status:** MEDIUM / STATIC REVIEW ONLY.
- **Location:** `app/Services/DepartmentPrintingService.php:33`; `app/Services/PrintJobService.php:37,54,59,74`; `app/Services/ReceiptPrintingService.php:41`.
- **Expected:** Job creation is required and implemented; operational printing also needs an identified consumer and a safe way to reconcile uncertain delivery.
- **Suggested reproduction:** Approve an order with a NETWORK department printer and inspect its pending job in an isolated environment; separately stop a retry after its persisted claim using an authorized test harness.
- **Inspected behavior / inferred outcome:** Department enqueue only inserts PENDING jobs. The inspected application has no pending-job dispatcher/consumer; only initial network receipts and FAILED network retries invoke transport. Retry refuses PENDING with the code message 'Only failed print jobs can be retried.' Local/USB jobs deliberately await an external agent. An interrupted retry also remains PENDING. An externally deployed consumer was not verified. This is an operational limitation, not proof that required job creation fails.
- **Suspected root cause:** Deferred delivery relies on infrastructure outside the inspected repository; retry's conservative claim has no implemented lease/reconciliation path.
- **Runtime error evidence:** None; not executed.

### S09 — Department routing silently omits unassigned jobs and queues inactive printers

- **Severity / status:** MEDIUM / STATIC REVIEW ONLY.
- **Location:** `app/Services/DepartmentPrintingService.php:15,33`; `app/Services/OrderApprovalService.php:27`.
- **Expected:** Required departmental work should remain observable when a printer is absent or inactive; inactive printers must not be treated as available.
- **Suggested reproduction:** Approve sellable products with (a) no department/printer and (b) a linked inactive department printer, using a safe test database.
- **Inspected behavior / inferred outcome:** Missing printer_id skips the item without recording a job or a routing error. An assigned inactive printer is not checked and receives a PENDING job. Approval can still complete. No physical send or false PRINTED status occurs in this path; severity concerns missing/undeliverable kitchen work. Whether an unassigned product intentionally needs no kitchen ticket is a policy question.
- **Suspected root cause:** Routing eligibility checks only printer_id presence, without availability or an explicit unroutable state.
- **Runtime error evidence:** None; not executed.

### S10 — Financial totals and top products apply different cancelled-order filters

- **Severity / status:** LOW / STATIC REVIEW ONLY.
- **Location:** `app/Services/FinancialReportService.php:37,76`.
- **Expected:** Reports should use a consistent definition of eligible sales.
- **Suggested reproduction:** In a dedicated test database, use a legacy/imported PAID+CANCELLED order with an ACTIVE item and compare financial and top-product results for its paid_at date.
- **Inspected behavior / inferred outcome:** Financial paidOrders excludes CANCELLED; topProducts filters only PAID and ACTIVE items. Inferred result: the item appears in top products but not financial sales. Normal payment/cancellation services prevent producing this combined state; no existing corrupt row or ordinary API reproduction is claimed.
- **Suspected root cause:** Report query predicates differ and database status checks do not enforce this cross-field invariant.
- **Runtime error evidence:** None; not executed.

### S11 — Financial daily breakdown accepts an unbounded date span

- **Severity / status:** MEDIUM / STATIC REVIEW ONLY.
- **Location:** `app/Http/Requests/FinancialReportRequest.php:17`; `app/Services/FinancialReportService.php:62`.
- **Expected:** A report request should have a practical resource bound while still including zero-activity days.
- **Suggested reproduction:** After separate authorization, request a multi-century valid from/to range in a resource-limited test environment; avoid load-testing development.
- **Inspected behavior / inferred outcome:** Validation checks date format and ordering but no maximum span. financial materializes one array row per day even when no data exists. A range such as 1000-01-01 through 9999-12-31 implies millions of rows and risks timeout/memory exhaustion. This is an inferred authenticated-admin availability risk, not a measured failure.
- **Suspected root cause:** Unbounded user-controlled date loop and response allocation.
- **Runtime error evidence:** None; not executed.

### S12 — MySQL test configurations do not select an isolated database

- **Severity / status:** HIGH / STATIC REVIEW ONLY.
- **Location:** `phpunit.mysql.xml:5`; `phpunit.orders.xml:5`; `tests/Feature/OrderWorkflowTest.php:19`; `tests/run-postman.php:32`; `tests/verify-recipe-migration.php:24`.
- **Expected:** Business tests must target an explicitly verified dedicated database and must not write development data.
- **Suggested reproduction:** Inspect the two MySQL PHPUnit configurations alongside the current environment; do not run them as configured.
- **Inspected behavior / inferred outcome:** Both set APP_ENV=testing and DB_CONNECTION=mysql but omit DB_DATABASE. Current .env resolves to development karem_altin; no .env.testing or dedicated override was found. Feature tests use DatabaseTransactions and create/modify fixtures. Rollback is not authorization to write development and does not prove complete isolation. The alternative runners create and migrate databases, which this task forbids.
- **Suspected root cause:** Test environment name is mistaken for database isolation unless an external explicit override is supplied.
- **Runtime error evidence:** None; not executed.

## F. Blocked and not-run tests

### Existing suite accounting

| Execution unit | Count | Status | Reason |
|---|---:|---|---|
| CreateEmployeeTest | 30 | BLOCKED | Requires writes to a verified isolated MySQL database |
| ManagementModulesTest | 20 | BLOCKED | Same |
| OrderWorkflowTest | 15 | BLOCKED | Same; stock/order/printing fixtures |
| OrdersReportsTest | 17 | BLOCKED | Same; existing mocks do not eliminate database writes |
| ProductsRecipesTest | 32 | BLOCKED | Same; MySQL-specific guards and fixture writes |
| RemainingModulesTest | 55 | BLOCKED | Same; tokens, inventory, employees and expense writes |
| tests/run-postman.php | 1 runner | BLOCKED | Creates database at line 32 and migrates at line 45; starts HTTP servers and mutating scenarios |
| tests/verify-recipe-migration.php | 1 runner | BLOCKED | Creates database at line 24 and migrates at line 46; modifies trigger/schema state |
| Feature/ExampleTest | 1 | NOT RUN | Root welcome-page rendering is outside backend API coverage; avoided application/view-cache writes for a non-business smoke test |

Safely executing the database cases requires an already provisioned, explicitly dedicated MySQL/MariaDB test database with the project's schema and trigger behavior, an isolated application configuration that demonstrably targets it, and the existing transport fakes/localhost fixtures. Provisioning or changing that configuration requires separate authorization. Existing standalone runners would additionally require permission for their database creation/migration lifecycle or an independently provided compatible execution environment; they were not rewritten.

### Requirements reviewed, not executed

All rows in this subsection are **STATIC REVIEW ONLY**; the corresponding existing database tests are BLOCKED above. Missing scenarios would require new tests or an authorized manual test environment, neither of which was created.

| Area / requested scenario | Inspected implementation and remaining evidence gap |
|---|---|
| Authentication | AuthenticationService checks active account and password hash, creates Sanctum token; logout deletes current token only. Existing tests cover valid login/token use and token revocation. Wrong-password, unknown-username and inactive-login negative cases were not found as explicit dedicated tests. None of these API requests ran |
| Employee create/update/status | Explicit validated fields, hashed passwords, unique usernames, public resource fields; EmployeeService locks users for last-active-admin guard and prevents own deactivation; middleware checks active state on every protected request; history retained. All role/token/race behavior remains unverified |
| Products | ProductService creates stock only for raw/purchased types, preserves stock on updates, keeps item_type immutable through requests, permits unit change. Admin listing can include inactive; catalog filters active categories/products and sellable types. Seeder inconsistency S06 |
| Recipes | Positive quantities, unique parent/component pair, physical component types, parent-scoped updates/deletes. RecipeService hard-deletes component only. Metadata shows purchased-component guards installed. No actual insert/update rejection or rollback test ran; mutable-recipe order risk S02 |
| Categories | Existing list/detail/create/update endpoints, unique names and activation; catalog filters inactive categories; no product hard deletion. No separate category DELETE endpoint is required by “CRUD operations that exist” |
| Departments | List/create/update/deactivate, DEPARTMENT printer validation and relationship; nullable printer supported and history retained. Routing gaps S09 |
| Printers | Enumerated types/connections, activation and history; receipt/retry checks active printer, department enqueue does not. No real device contacted |
| Tables | Creation forces AVAILABLE; unique number; normal update forbids status, rejects occupied deactivation; list associates active order when occupied. Generated unique active-table key and service locking inspected, race not executed |
| Settings | Existing keys only, generic string value up to 255; empty/whitespace preserved by middleware exception; unknown key 404. Setting model disables CREATED_AT and maintains updated_at. No settings writes performed |
| Manual PURCHASE/IN 24 | InventoryService locks physical stock, calculates balance, writes stock and exactly one movement in transaction. Proposed result 0 to 24 is unverified |
| Manual ADJUSTMENT/OUT 3 | Same service; authenticated created_by, optional Arabic note, resulting 24 to 21 is unverified |
| Manual WASTE/OUT 5 against 2 | Shortage checked before write; expected 422/no stock/history change remains unverified. Requests reject zero/negative amounts, invalid type/direction, order-owned movement types and produced stock |
| Inventory history / atomicity | Product/type/direction/date filters include order movements. Sorted locks for order stock, manual row lock and transaction inspected. No actual rollback or competing writers executed |
| Payment | PaymentService locks order/table, only allows PREPARING, sets PAID/CLOSED and frees table without consuming stock; duplicate PAID throws 409; other invalid state 422. Current bodyless contract does not collect amount_paid/change_amount; new orders keep those nullable values. This is an unsupported tender/change calculation capability, not proof of arithmetic failure |
| Printing | Approval generates department job records with item/quantity/note and saved payload when routed; receipt department is null; network I/O outside DB transactions. Retry only FAILED, claims under lock, preserves payload and increments network attempt. PENDING/PRINTED rejected. S05/S07-S09 and uncertain-delivery limitations require verification |
| Dashboard | Default today uses app timezone; optional date filters for financial/state counts, live tables and recent active orders remain outside period, top5. No live totals independently reconciled |
| Financial report | Required ordered dates; paid_at sales (not created_at), ACTIVE expense_date expenses, decimal arithmetic and zero-activity days; daily sums derive totals. Yesterday-created/today-paid semantics inspected only. S10/S11 |
| Top products | PAID orders, ACTIVE items, paid_at filtering, saved subtotals/quantities, descending quantity, default10/custom1..100, inactive products retained; no current-price substitution. Conditional inconsistency S10 |
| Expenses integration | Create ACTIVE with authenticated creator; update/cancel preserve record; cancelled expenses excluded by report query. Existing lifecycle and report fixtures cover separate parts; a create → report → cancel → report HTTP workflow was not identified as one end-to-end test |
| Security | auth:sanctum + active for protected routes, admin on management/reporting/approval/cancel/pay/retry; validated input and explicit assignments; User hides password hash. Read ownership implemented but write/receipt gap S01. No full endpoint×role runtime matrix executed |
| Constraints | FKs, unique usernames/recipe pairs/table numbers, enum CHECKs, active-table generated unique index, recipe triggers inspected. Inventory nonnegativity and physical-type membership rely on application rules rather than equivalent DB checks. This does not itself prove a negative-stock API bug |
| Form-data | 5 collection entries use form-data: employee update/status, manual movement, expense create/update. Spoofed updates use POST with _method=PATCH; other body entries use raw JSON, including literal PATCH. Nested order fields should use items[0][product_id]/quantity/note; booleans should use 0/1 for Laravel boolean validation. Actual multipart parsing was not executed for any endpoint; no compatibility failure is claimed |

### Order scenarios A–N

All numeric outcomes below are **expected**, never measured in this audit.

| ID | Expected behavior and inspected path | Static assessment / gap |
|---|---|---|
| A | Create meal ×3, chicken10/cola20, chairs2: PENDING_APPROVAL, authenticated employee, price/chair snapshots, totals, table occupied, no stock movement | OrderCreationService uses transaction/table lock, daily GET_LOCK number allocation and active-table unique key. Duplicate-table rejection not executed |
| B | Approve: chicken9.250, cola17, ORDER_CONSUMPTION, PREPARING, department jobs | OrderApprovalService aggregates then applies stock at approval, not creation. Repeat state guard inspected only |
| C | Chicken0.500 for required0.750: reject, pending retained, no writes/jobs | InventoryService preflights shortages before stock writes; approval transaction includes status/jobs. Rollback unverified |
| D | Cola1 for required3 while chicken10: reject without chicken deduction | Same all-products shortage preflight; no partial-write scenario executed |
| E | Cancel pending: CANCELLED, table available, no ORDER_RETURN | OrderCancellationService only returns stock for PREPARING |
| F | Cancel approved: restore chicken10/cola20, preserve consumption and record returns once | Uses net ORDER_CONSUMPTION minus ORDER_RETURN history and state lock; double cancellation not executed |
| G | Pending 2 to3: no stock until approval; final quantity consumed | OrderUpdateService applies stock only for PREPARING |
| H | Approved 2 to3: consume only extra chicken0.250/cola1 | Before/after rounded requirement difference for unchanged recipes; mutable recipes S02 |
| I | Approved 3 to1: return chicken0.500/cola2 | Negative requirement delta returned; recipe/tracking changes invalidate assumption, S02 |
| J | Failed increase: old items/totals/stock/jobs preserved | Item edits, inventory and job enqueue are within same transaction; actual rollback not tested |
| K | Remove approved item: delete item, recalculate totals, return consumed stock, cancellation job | Hard-delete implementation inspected; S02 can return incorrect amount after recipe edits |
| L | Shared ingredient aggregation, shortage checks, correct update/cancel returns | Approval aggregates correctly in code; replacement can falsely reject S03; recipe edits S02 |
| M | Purchased cola ×4:20 to16 on approve, back20 on cancellation | Direct tracked PURCHASED_PRODUCT requirement and movement-ledger cancellation inspected |
| N | Reject invalid produced recipe at approval | Tracked empty recipe rejected in InventoryService::requirements; creation accepts pending product; untracked bypass S04 |

### Concurrency and side effects

All seven requested concurrency categories are **BLOCKED**, not passed. Existing live runner contains competing same-table creations, limited-stock approvals, manual stock movement races, duplicate payments and printer retries, plus employee/expense races. They were not started. Sequential repeat approval/cancellation assertions do not establish concurrent safety; dedicated simultaneous same-order approval/cancellation coverage was not established.

Static protections: row locks on order/table/stock; sorted inventory locks; transaction retries; generated unique active-table index; GET_LOCK for daily numbering; last-admin user locks; print retry persisted PENDING claim before external I/O. These mechanisms are evidence of design intent, not proof against deadlocks, duplicated effects, or all race interleavings. Recipe changes and department routing are not frozen with order approval snapshots.

No physical device verification is appropriate under this task. Existing tests use mocks and localhost/unreachable fixtures, but their database setup remains blocked. A TCP write succeeding also cannot prove that paper was physically printed.

### Complete API inventory

All endpoint behavior below is **STATIC REVIEW ONLY**, not a record of executed HTTP results. All paths have the /api prefix. Public login uses throttle:10,1; “Active” means api + auth:sanctum + active; “Admin” adds admin middleware. Protected routes can return 401 unauthenticated or 403 inactive/wrong role; bound missing IDs/keys return 404; validation generally returns 422. Resource responses normally use data; pagination adds links/meta. Expected normal status codes and particular side effects/errors follow.

| Method / URL | Permission | Inputs, expected response, database operations, side effects and failures |
|---|---|---|
| GET /api/catalog | Active | no body; 200 category/product collection; read active categories and active sellable products; no stock write |
| GET /api/categories | Admin | is_active/search query; 200 categories including inactive unless filtered; read only |
| POST /api/categories | Admin | unique name up to 100; 201 category; insert active; duplicate/invalid 422 |
| GET /api/categories/{category} | Admin | category ID; 200 category and products; read only |
| PATCH /api/categories/{category} | Admin | optional name/is_active; 200 category; no product deletion; duplicate/invalid 422 |
| GET /api/dashboard/summary | Admin | optional ordered from/to dates, default today; 200 totals/state counts/live tables/top5/recent active; reads only |
| GET /api/departments | Admin | no body; 200 departments/printers; read only |
| POST /api/departments | Admin | unique name, optional nullable DEPARTMENT printer_id; 201 department; insert active; wrong printer type/duplicate 422 |
| PATCH /api/departments/{department} | Admin | optional name/printer_id/is_active; 200 department; validate printer type, retain history; invalid 422 |
| GET /api/employees | Admin | role/is_active/search query; 200 safe employee collection; read only |
| POST /api/employees | Admin | name, unique username, password, ADMIN/EMPLOYEE role; 201 user; hash password and force active; duplicate/invalid 422 |
| PATCH /api/employees/{employee} | Admin | optional name/username/password/role; 200 user; hash changed password, preserve last active admin; duplicate/protected fields/last-admin 422 |
| PATCH /api/employees/{employee}/status | Admin | is_active boolean; 200 user; reject self-deactivation and last-admin loss; keep history and block inactive tokens; invalid 422 |
| GET /api/expenses | Admin | from/to/status/created_by/search query; 200 expenses; expense_date filters; read only |
| POST /api/expenses | Admin | name, positive amount, expense_date Y-m-d, optional note; 201 expense; ACTIVE with authenticated creator; no inventory effects; invalid 422 |
| PATCH /api/expenses/{expense} | Admin | optional expense fields; 200 expense; update active record only; protected fields/invalid state 422 |
| POST /api/expenses/{expense}/cancel | Admin | no body; 200 expense; set CANCELLED, retain record, no stock writes; repeated/invalid cancellation 422 |
| GET /api/inventory | Admin | physical item_type/is_active/search/low_stock_only query; 200 physical stock collection; current/min stock; read only |
| GET /api/inventory/movements | Admin | product/type/direction/from/to query; 200 movement collection with product/creator; includes order movements; read only |
| POST /api/inventory/movements | Admin | product_id, transaction_type, direction, positive quantity, optional note; 201 movement/current_quantity; lock stock and insert one movement atomically with authenticated creator; invalid type/produced product/shortage/overflow 422 |
| POST /api/login | Public, throttled | username/password required strings; 200 token/type/user; insert current Sanctum token; invalid/inactive credentials rejected, throttle 429 |
| POST /api/logout | Active | no body; 200 message; delete only current access token; later use unauthorized |
| GET /api/orders | Active | status/payment/employee/table/date/search/page filters; 200 paginated summaries; employee ownership scope; invalid filters 422 |
| POST /api/orders | Active | table_id, chairs_count, items[product_id,quantity,note]; 201 order; daily unique number, snapshots and totals, table OCCUPIED; no stock deduction; unavailable table/invalid product/overflow 422 |
| GET /api/orders/{order} | Active | order ID; 200 order/items/table/employee snapshots; ownership check 403; read only |
| PATCH /api/orders/{order} | Active | optional chairs_count/items replacement; 200 order; editable pending/preparing only; item totals and approved stock deltas/jobs in transaction; paid 409, invalid state/shortage 422; S01-S05 |
| POST /api/orders/{order}/approve | Admin | no body; 200 order; lock pending order, aggregate consumption, stock movements, PREPARING and department jobs in one transaction; repeat/shortage/invalid recipe 422 |
| POST /api/orders/{order}/cancel | Admin | no body; 200 order; pending no stock return, preparing net movement return and jobs; CANCELLED/table AVAILABLE; paid 409, repeat 422 |
| POST /api/orders/{order}/pay | Admin | no body; 200 order; PREPARING to CLOSED/PAID, paid_at/closed_at, table AVAILABLE; no stock write; duplicate 409, other states 422 |
| POST /api/orders/{order}/print-receipt | Active | no body; 200 data plus print_job; exactly one active receipt printer; save receipt payload, network I/O after transaction, local pending; invalid printer selection 422; S01/S07 |
| GET /api/print-jobs | Admin | status/type/printer/department/order/date/page filters; 200 paginated jobs; read only |
| POST /api/print-jobs/{printJob}/retry | Admin | no body; 200 job; lock FAILED, validate active printer/config, claim PENDING, send saved payload outside transaction for NETWORK; local requeue, attempts reflect sends; wrong state/config 422 |
| GET /api/printers | Admin | no body; 200 printers; read only |
| POST /api/printers | Admin | name, DEPARTMENT/RECEIPT type, NETWORK/USB/LOCAL_AGENT connection, optional config; 201 active printer; insert only, no print; invalid 422 |
| PATCH /api/printers/{printer} | Admin | optional printer fields/is_active; 200 printer; retain jobs, reject linked department printer conversion to RECEIPT; invalid 422 |
| GET /api/products | Admin | item_type scalar/array, category/department/is_active/search filters; 200 products including inactive unless filtered; read only |
| POST /api/products | Admin | name/type/unit, sellable price, physical min_stock, optional category/department/stock tracking; 201 product; physical stock row zero, produced none; invalid 422 |
| GET /api/products/{product} | Admin | product ID; 200 product with recipe/category/department; read only |
| PATCH /api/products/{product} | Admin | optional mutable product fields; 200 product; unit editable, item_type forbidden, stock preserved; invalid 422 |
| POST /api/products/{product}/ingredients | Admin | raw_material_id existing RAW/PURCHASED, positive quantity_required; 201 ingredient; produced parent lock, unique component; invalid/duplicate 422 |
| PATCH /api/products/{product}/ingredients/{ingredient} | Admin | positive quantity_required; 200 ingredient; parent-scoped component update, no stock history rewrite; invalid 422 |
| DELETE /api/products/{product}/ingredients/{ingredient} | Admin | parent/component IDs; 204 empty; hard-delete only component, keep product/history; missing/cross-parent 404 |
| PATCH /api/products/{product}/status | Admin | is_active boolean only; 200 product; toggle active, preserve history; invalid 422 |
| GET /api/reports/financial | Admin | required ordered from/to Y-m-d; 200 totals and zero-filled daily breakdown; PAID paid_at sales, ACTIVE expense_date expenses; invalid 422; S10/S11 |
| GET /api/reports/top-products | Admin | required from/to and optional limit1..100 default10; 200 aggregate product collection; PAID/ACTIVE items by paid_at using saved subtotal, inactive products retained; invalid 422; S10 |
| GET /api/settings | Admin | no body; 200 key/value collection; read only |
| PATCH /api/settings/{key} | Admin | existing key and present string value max255, including numeric-looking/empty strings; 200 setting; value/updated_at write; missing key 404, invalid value 422 |
| GET /api/tables | Admin | no body; 200 tables with active_order or null; read only |
| POST /api/tables | Admin | unique integer table_number, nonnegative default_chairs; 201 table; force AVAILABLE; duplicate/invalid 422 |
| PATCH /api/tables/{table} | Admin | optional table_number/default_chairs/is_active; 200 table; status forbidden, occupied deactivation rejected, no implicit order cancellation; invalid 422 |

No endpoint required by the stated existing management/workflow scope was found missing from this 50-route inventory. Pending-job delivery/reconciliation is the operational gap described in S08; this audit did not add endpoints.

### Discovered PHPUnit manifest

The following is the complete discovered case list, including data-provider expansions. Business feature entries are BLOCKED; the root-page feature example is NOT RUN; only the unit example is PASS.

| Case | Status |
|---|---|
| `Tests\Feature\CreateEmployeeTest::test_admin_creates_user_with_hashed_password_and_public_response#0` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_admin_creates_user_with_hashed_password_and_public_response#1` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_duplicate_username_is_rejected` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_required_fields#0` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_required_fields#1` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_required_fields#2` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_required_fields#3` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#0` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#1` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#2` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#3` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#4` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#5` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#6` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#7` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#8` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_invalid_fields#9` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#0` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#1` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#2` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#3` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#4` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#5` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#6` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#7` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_protected_fields_are_rejected#8` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_unauthenticated_request_is_rejected` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_employee_is_forbidden` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_inactive_admin_with_existing_token_is_forbidden` | BLOCKED |
| `Tests\Feature\CreateEmployeeTest::test_database_unique_constraint_race_is_mapped_to_validation` | BLOCKED |
| `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` | NOT RUN |
| `Tests\Feature\ManagementModulesTest::test_category_list_details_filters_and_status_preserve_products` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_category_creation_unique_name_and_defaults` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_department_assignment_detachment_status_and_history` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_printer_creation_with_existing_connection_types#0` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_printer_creation_with_existing_connection_types#1` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_printer_creation_with_existing_connection_types#2` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_printer_validation_linked_type_guard_and_deactivation_preserve_history` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_tables_create_update_and_status_protection` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_occupied_table_exposes_active_order_and_cannot_be_deactivated` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_cancelled_order_is_not_returned_as_active` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_generic_setting_values_are_preserved#0` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_generic_setting_values_are_preserved#1` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_generic_setting_values_are_preserved#2` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_generic_setting_values_are_preserved#3` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_generic_setting_values_are_preserved#4` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_generic_setting_values_are_preserved#5` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_settings_listing_unknown_key_and_schema_validation` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_management_relations_are_eager_loaded` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_all_management_routes_enforce_auth_active_and_admin` | BLOCKED |
| `Tests\Feature\ManagementModulesTest::test_missing_entities_return_not_found_and_no_delete_routes_exist` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_creation_snapshots_totals_and_rejects_occupied_table` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_raw_inactive_and_client_priced_products_are_rejected_atomically` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_approval_deducts_recipes_groups_printing_and_rejects_repeat` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_insufficient_stock_rolls_back_entire_approval_and_update` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_update_deltas_and_cancellation_restore_stock_and_preserve_history` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_pending_update_and_cancellation_never_move_stock_or_print` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_payment_closes_frees_table_and_paid_orders_are_immutable` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_receipt_network_failure_is_successful_response_before_and_after_payment` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_receipt_local_agent_stays_pending_and_missing_or_ambiguous_printer_is_rejected` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_authentication_roles_and_not_found_responses` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_fractional_math_nontracked_items_and_repeated_edits` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_daily_sequence_increments_and_resets` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_receipt_success_sends_complete_payload_to_socket` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_shared_material_requirements_are_aggregated_before_deduction` | BLOCKED |
| `Tests\Feature\OrderWorkflowTest::test_unique_active_table_guard_and_decimal_validation` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_order_ownership_admin_access_and_paginated_summaries` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_order_filters_individually_combined_and_creation_date_semantics` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_order_details_preserve_historical_prices_and_include_cancelled_items` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_print_job_filters_pagination_nullable_department_and_read_only_listing` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_retry_claim_blocks_reentry_and_sends_snapshot_outside_its_transaction` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_network_retry_failure_keeps_failed_and_increments_attempts` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_only_failed_jobs_can_be_retried#0` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_only_failed_jobs_can_be_retried#1` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_printer_validation_and_non_network_requeue_never_claims_printed` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_financial_daily_breakdown_zero_days_and_totals_use_correct_dates` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_dashboard_today_uses_app_timezone_and_keeps_live_sections_outside_period` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_dashboard_counts_every_schema_status_and_negative_cash_flow` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_financial_aggregates_can_exceed_an_individual_money_column` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_top_products_historical_subtotals_fractional_quantities_ties_and_limits` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_report_validation_requires_dates_and_limits_and_checks_range` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_new_routes_require_auth_active_and_admin_where_appropriate` | BLOCKED |
| `Tests\Feature\OrdersReportsTest::test_read_queries_are_bounded_and_do_not_lazy_load_order_items` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_admin_list_includes_all_types_inactive_products_and_relations_without_recipes` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_single_multiple_and_other_filters` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_employee_catalog_excludes_raw_inactive_products_and_inactive_categories` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_lists_eager_load_relations_with_bounded_query_counts` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_creation_and_inventory_normalization#0` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_creation_and_inventory_normalization#1` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_creation_and_inventory_normalization#2` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#0` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#1` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#2` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#3` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#4` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#5` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#6` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#7` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#8` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#9` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_product_validation#10` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_updates_allow_unit_but_not_type_and_preserve_balances_and_history` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_status_only_changes_activity_and_preserves_recipe_inventory_and_history` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_details_quantities_units_duplicate_guard_and_hard_delete` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_quantity_validation#0` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_quantity_validation#1` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_quantity_validation#2` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_quantity_validation#3` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_quantity_validation#4` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_quantity_validation#5` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_recipe_parent_component_and_cross_parent_guards` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_purchased_components_are_consumed_and_recipe_edits_do_not_rewrite_history` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_empty_tracked_recipe_cannot_silently_approve_an_order` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_database_triggers_enforce_parent_and_component_types_on_insert_and_update` | BLOCKED |
| `Tests\Feature\ProductsRecipesTest::test_authorization_for_all_management_endpoints_and_catalog` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_logout_revokes_only_current_token_and_leaves_account_unchanged#0` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_logout_revokes_only_current_token_and_leaves_account_unchanged#1` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_inventory_current_values_types_inactive_filters_and_low_stock` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_manual_movement_updates_stock_and_exactly_one_history_record#0` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_manual_movement_updates_stock_and_exactly_one_history_record#1` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_manual_movement_updates_stock_and_exactly_one_history_record#2` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_manual_movement_updates_stock_and_exactly_one_history_record#3` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#0` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#1` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#2` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#3` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#4` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#5` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#6` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#7` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#8` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#9` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#10` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#11` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#12` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#13` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#14` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#15` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#16` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#17` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#18` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_manual_movements_leave_stock_and_history_unchanged#19` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_stock_shortage_overflow_missing_record_and_produced_products_are_rejected` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_movement_insert_failure_rolls_back_stock_update` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_inactive_raw_material_can_be_managed_and_out_can_reach_zero` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_history_includes_order_movements_and_filters_dates_products_types_and_directions` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_employee_listing_filters_and_safe_response_fields` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_employee_partial_updates_password_policy_and_unique_username` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_employee_status_blocks_existing_tokens_preserves_history_and_prevents_self_deactivation` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_last_active_admin_cannot_be_demoted_or_deactivated` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_expense_lifecycle_uses_form_updates_preserves_records_and_never_touches_stock` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#0` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#1` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#2` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#3` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#4` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#5` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#6` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#7` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#8` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#9` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#10` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#11` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#12` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#13` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_invalid_expense_create_and_update_fields_are_rejected#14` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_expense_filters_use_expense_date_not_creation_date` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_listing_relations_are_eager_loaded` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_all_new_routes_enforce_authentication_active_account_and_role` | BLOCKED |
| `Tests\Feature\RemainingModulesTest::test_missing_resources_and_unrequested_routes` | BLOCKED |
| `Tests\Unit\ExampleTest::test_that_true_is_true` | PASS |

## G. Critical business inconsistencies

- **Unauthorized access:** S01 is a high-severity inferred mismatch between read ownership checks and write/receipt access. No exploit was attempted.
- **Incorrect returns / missing deductions:** S02 can over-return stock or fail to restore the originally consumed component after recipe/tracking changes. S04 conditionally bypasses empty-recipe checks. No development balances were sampled or altered.
- **Partial approval / negative stock / repeated deduction:** No executed evidence establishes a defect or proves safety. Transactions, shortage preflight, state guards and locking were inspected. S03 is a false-shortage risk rather than proof of a partial approval.
- **Independent produced inventory:** S06 makes seeder output inconsistent with API inventory creation.
- **Order/table and payment state:** Code transitions are consistent with pending/preparing/cancelled/closed intent; payment does not consume stock again. Duplicate/repeated operations were not run. Tender/change calculations are not part of the current bodyless payment implementation.
- **Reporting:** S10 only arises with an inconsistent PAID+CANCELLED parent state; ordinary services prevent that state. S11 is a report resource-bound risk. No incorrect live revenue total was observed.
- **Printing:** S07 can classify an already-transmitted receipt as retryable after a persistence failure. S05 can leave kitchen notes stale. S08/S09 require operational delivery/availability clarification; no job was falsely reported as physically verified.
- **Execution safety:** S12 blocked meaningful runtime verification; “testing” APP_ENV alone is insufficient isolation.

## H. Final testing verdict

**INSUFFICIENT TEST EVIDENCE**

The only executed test was a trivial database-free unit example. The 169 business feature cases and two standalone integration runners could not be safely executed under the authorized constraints. Static review identified 12 findings/risks: 4 HIGH, 7 MEDIUM and 1 LOW. These are not reproduced failures; some depend on policy, external printing infrastructure, fault injection, or pre-existing inconsistent data.

Production readiness cannot be certified. Highest-priority verification is employee order ownership, approved-order edits after recipe/tracking changes, and receipt persistence failure after transmission. Isolated MySQL workflow, rollback, authorization, multipart and true concurrency evidence is still required.

No correction was attempted. Review findings individually before authorizing any implementation, environment provisioning, or new tests.

## I. Confirmation of unchanged project

A pre-audit SHA-256 snapshot covered 211 project files found by rg, including source, routes, migrations, seeders, tests, configuration, documentation and Postman assets. Immediately before writing this report, comparison found **zero changed, added or deleted files**. The final comparison found **zero existing files changed, zero files deleted, and exactly one file added: docs/testing/integration-test-report.md** (212 files afterward). Git status independently showed only the new docs/testing/ directory.

- Application source/controllers/services/requests/resources/models/middleware: unchanged.
- Routes: unchanged.
- Migrations and seeders: unchanged; none executed.
- Existing tests: unchanged; no tests added.
- Postman collection: unchanged.
- Configuration and .env: unchanged.
- Packages: no installation, upgrade or dependency changes.
- Git: no commits, branch switches or repository mutations performed.
- Development database: no application-data or schema writes issued by this audit; diagnostics were metadata-only. No migrations, seeders, fixture writes or business API calls ran. This is an account of audit actions, not a claim to detect writes by unrelated external processes.
- Printers: no print requests or device connections issued.
- Only deliverable created: this report.

The hash comparison excludes .git, vendor, node_modules, storage and bootstrap/cache; it is not a full external-system integrity snapshot. No formatters, automatic fixes, new tests or source changes were made.

