# Order API

All endpoints return JSON. Send `Accept: application/json` and `Content-Type: application/json`.

## Run and authenticate

```sh
composer install
php artisan migrate
php artisan serve
```

BCMath is required and declared in Composer. Sanctum is installed; its token-table migration is the only new migration in this implementation. Existing domain migrations, seeders, and relationships are retained.

`POST /api/login` accepts `{"username":"admin","password":"admin123"}` when the existing development UserSeeder credentials are in use. Use the returned `token` in `Authorization: Bearer <token>`. Login checks the existing `password_hash` column and rejects inactive users. Login is limited to 10 requests/minute; inactive accounts also cannot use previously issued tokens. Token auth follows the [Laravel Sanctum documentation](https://laravel.com/docs/12.x/sanctum#issuing-api-tokens).

| Method | Path | Body | Role |
| --- | --- | --- | --- |
| POST | `/api/orders` | `table_id`, `chairs_count`, `items` | Authenticated |
| POST | `/api/orders/{order}/approve` | None | ADMIN |
| PATCH | `/api/orders/{order}` | Optional `chairs_count`, `items` | Authenticated |
| POST | `/api/orders/{order}/cancel` | None | ADMIN |
| POST | `/api/orders/{order}/pay` | None | ADMIN |
| POST | `/api/orders/{order}/print-receipt` | None | Authenticated |

Example creation body (replace IDs with existing products/table):

```json
{
  "table_id": 1,
  "chairs_count": 2,
  "items": [
    {"product_id": 8, "quantity": "2.00", "note": "بدون ملح"},
    {"product_id": 14, "quantity": "1.00"}
  ]
}
```

Order responses use `{ "data": { ... , "items": [...] } }`; monetary values and quantities are decimal strings. Receipt responses also include `print_job` with ID, payload, status, attempts, and timestamps. Creation returns 201, other successful operations 200. Business violations return 422 with Arabic `message`; stock failures include structured `shortages`. Paid-order mutations return 409; missing orders 404, missing/invalid tokens 401, role failures 403.

## State, precision, and concurrency

- State transitions: `PENDING_APPROVAL -> PREPARING -> CLOSED`; pending/preparing orders may become `CANCELLED`. No transition writes `APPROVED` or `READY`.
- Each mutation locks a fresh order row inside a transaction. Creation locks its table; stock rows are locked in product-ID order. Transactions retry deadlocks up to three times. The existing active-table unique index remains a final guard, mapped to an Arabic business exception.
- Numbers use `ORD-YYYYMMDD-NNNN`, in the application's configured timezone. A database-specific MySQL/MariaDB `GET_LOCK` serializes creation through commit. Inside the transaction, the next number is today's greatest formatted suffix plus one, starting at 0001. It includes cancelled orders and refuses to exceed 9999. This also handles concurrent first orders of the day; a plain count in a transaction would not. The lock is released in `finally` after commit/rollback.
- Actual schema precision is money `(10,2)`, order-item quantity `(10,2)`, and recipe/inventory/ledger quantity `(12,3)`. Requests reject more than two item-quantity decimals, negative quantities, duplicate product IDs, and client prices. BCMath performs all arithmetic. Rounding is half-up at each persisted line/recipe requirement; totals sum persisted line subtotals. Update stock deltas use the difference between rounded old/new requirements to avoid drift from repeated fractional edits. Values exceeding column capacity return 422.
- Chair and unit prices are snapshots. New items use current product prices; existing items retain their unit prices. Payment leaves `amount_paid` and `change_amount` null.
- Cancellation reverses net ledger consumption, retains all remaining items and historical stock/print rows, and frees the table.

## Update semantics and assumptions

- Omitting `items` preserves the item list. Supplying `items` replaces its desired final state: omission of a current product or quantity zero permanently deletes that line. An empty list removes every active line. Chairs-only updates are supported.
- Creation requires at least one item. Chairs may be zero. Duplicate products are rejected rather than silently merged. Requests support at most 200 item entries and 1,000 characters per note.
- Increasing an existing inactive product is rejected; reducing/removing it remains possible. Non-stock-tracked products and non-stock-tracked recipe materials are skipped. Missing inventory is unavailable for deduction; a missing inventory record on a return yields a clear 422 configuration error.
- Approval and edit calculations use current recipes/tracking flags, as requested. There is no recipe snapshot schema; recipes/tracking should remain stable while orders are preparing. Cancellation always uses actual ledger history even if recipes later change.
- In an update with both returns and deductions, deductions must be covered by stock available before that update. The operation does not borrow stock from its own planned returns.
- Notes can be supplied for new/existing items. Omitting a note preserves it; explicit null clears it. A note-only edit does not create a kitchen quantity-change job.
- Authenticated employees can create, update, and print any order; only approval, cancellation, and payment require ADMIN, matching the requested authorization scope.

## Receipt printers

Exactly one active `RECEIPT` printer is required. Preferred NETWORK `connection_config`:

```json
{"ip":"192.168.1.50","port":9100}
```

`192.168.1.50:9100` and a bare IP (default port 9100) are also accepted. IPv6 can use JSON. Only valid literal IPs and ports 1–65535 are accepted.

The service stores an Arabic UTF-8 newline-delimited receipt, commits, then opens a raw TCP socket with a two-second connection timeout and bounded writes, handling partial writes. On successful transmission it marks the job `PRINTED` and timestamps it; this means bytes were sent, not that paper output was acknowledged. Arabic rendering depends on printer firmware/encoding support. On connection/write/configuration failure it returns HTTP 200 with job `FAILED`, increments attempts, and logs the failure. USB/LOCAL_AGENT stay `PENDING` with zero attempts for a future agent. Department jobs always remain pending and are grouped by department and change type; missing department printers are silently skipped.

## Validation

Verified on 2026-09-23 against the local MySQL/MariaDB setup:

- PHPUnit: 15 tests, 160 assertions passed.
- Postman collection executed with Newman over live HTTP: 23 requests, 45 assertions, zero failures.
- Persistent database and concurrency verification: 27 assertions passed (including same-table creation, unique numbering across different tables, competing stock approvals, and simultaneous payments).
- Receipt TCP success verified with a local socket listener; unreachable network and USB pending paths also verified.
- Pint, Composer manifest validation, and whitespace checks passed. Existing domain migration/seeder/model hashes were unchanged except for the required Sanctum trait addition to `User`.
- Only the personal-access-token migration was applied to the application database. Temporary HTTP-test servers and database were removed after validation.

```sh
php vendor/bin/phpunit -c phpunit.orders.xml
npx --yes newman --version
php tests/run-postman.php
```

The MySQL PHPUnit suite wraps fixtures in rollback transactions and never runs `migrate:fresh`. It uses the configured database, so migrations must already be applied. It covers stock/math/history assertions in addition to HTTP results; the default SQLite suite skips these MySQL-only tests.

The live runner creates a randomly named `order_api_test_<hex>` database, applies migrations only there, starts two local PHP servers, and executes [the Postman collection](postman/OrderAPI.postman_collection.json) with Newman. It verifies persistent database effects and sends concurrent requests through separate servers to exercise real locks. A `finally` block stops both servers and drops only that temporary database. MySQL CREATE/DROP DATABASE privileges and a cached Newman CLI are required; the application database is not reset. The generated Postman environment contains only temporary fixture credentials and is deleted afterward.

To run manually in Postman, import the collection and set `base_url`, `username`, `password`, `table_id`, `meal_id`, `drink_id`, `snack_id`, `extra_id`, `raw_id`. Login and order requests automatically capture tokens/IDs. The collection's exact amount/stock assertions expect the fixtures in `tests/Support/OrderFixtures.php` (chair price 15, meal 10.25 with 0.250 raw material, drink 3.10, snack 2.00, extra 1.50, inventory 100, unreachable NETWORK receipt printer). Use the isolated runner for a reproducible full run. The existing seeded USB receipt printer correctly stays pending until configured for NETWORK testing.

## Files

- Services (`app/Services`): `OrderCreationService.php`, `OrderApprovalService.php`, `OrderUpdateService.php`, `OrderCancellationService.php`, `PaymentService.php`, `ReceiptPrintingService.php`; shared `OrderDataService.php`, `InventoryService.php`, `DepartmentPrintingService.php`; minimal `AuthenticationService.php`.
- Controllers (`app/Http/Controllers/Api`): `OrderController.php`, `AuthController.php`.
- Requests (`app/Http/Requests`): `CreateOrderRequest.php`, `UpdateOrderRequest.php`, `LoginRequest.php`. Bodyless operations use route-model binding.
- Resources (`app/Http/Resources`): `OrderResource.php`, `OrderItemResource.php`.
- Exceptions (`app/Exceptions`): `InsufficientStockException.php`, `OrderAlreadyPaidException.php`, `InvalidOrderStateException.php`, `TableUnavailableException.php`.
- Middleware (`app/Http/Middleware`): `EnsureAdmin.php`, `EnsureActiveUser.php`.
- Routes: `routes/api.php`, registered in `bootstrap/app.php`, which also renders business exceptions as JSON.
- Auth setup: `HasApiTokens` added to existing `User`; `composer.json`/lock updated for Sanctum and BCMath; `2026_09_23_202042_create_personal_access_tokens_table.php` migration.
- Validation: `tests/Feature/OrderWorkflowTest.php`, `tests/Support/OrderFixtures.php`, `phpunit.orders.xml`, `tests/run-postman.php`, and the Postman collection.
