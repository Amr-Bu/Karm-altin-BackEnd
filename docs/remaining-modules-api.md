# Logout, inventory, employees and expenses

All paths below include the application's `/api` prefix. The existing Postman
`base_url` already includes that prefix (for example,
`http://127.0.0.1:8000/api`).

## Routes and authorization

| Method | Path | Access |
| --- | --- | --- |
| POST | /api/logout | Active ADMIN or EMPLOYEE |
| GET | /api/inventory | Active ADMIN |
| GET | /api/inventory/movements | Active ADMIN |
| POST | /api/inventory/movements | Active ADMIN |
| GET | /api/employees | Active ADMIN |
| PATCH | /api/employees/{employee} | Active ADMIN |
| PATCH | /api/employees/{employee}/status | Active ADMIN |
| GET | /api/expenses | Active ADMIN |
| POST | /api/expenses | Active ADMIN |
| PATCH | /api/expenses/{expense} | Active ADMIN |
| POST | /api/expenses/{expense}/cancel | Active ADMIN |

Every route uses `auth:sanctum` and `active`; all except logout also use
`admin`. Existing login, employee creation and all earlier APIs remain available.
There are no new show-by-ID, delete, reconciliation or service-method endpoints.
Logout returns `{"message":"Logged out successfully"}` and deletes only the
current personal access token. Other tokens and the user record remain intact.

## Inventory

`GET /inventory` reads `inventory_stock.current_quantity` directly and eagerly
loads physical products (RAW_MATERIAL and PURCHASED_PRODUCT), including inactive
products. Filters: `item_type`, `is_active=0|1`, `search` (name),
`low_stock_only=0|1`. Low stock means quantity <= non-null min_stock.

Manual movement fields: `product_id`, `transaction_type`, `direction`,
`quantity`, optional `note`. Allowed combinations:

| Type | Direction |
| --- | --- |
| PURCHASE | IN |
| WASTE | OUT |
| ADJUSTMENT | IN or OUT |

Quantity must be positive, at most 999999999.999, with up to three fractional
digits. Scientific notation and excess precision are rejected. Products must
have an existing physical stock record. Inactive physical products can be
managed. Each successful movement locks the stock row, changes its quantity and
inserts exactly one history record in the same transaction. Arithmetic uses
BCMath. Shortages use the existing 422 response with `shortages`; overflow is
also rejected without stock or history changes.

The server supplies `created_by` and null `reference_order_id`.
Client-supplied internal fields, including `actual_quantity`,
`balance_after` and `current_quantity`, are rejected.
The 201 resource contains the movement and the committed `current_quantity`.
That quantity is not stored on the history row.

`GET /inventory/movements` includes manual and order-generated movements.
Filters: `product_id`, `transaction_type`, `direction`, `from`, `to`.
Dates use movement `created_at`; either bound may be supplied. Results are
ordered by created_at descending, then ID descending. Each resource contains
the scalar `created_by` ID and a separate `creator: {id, name}` summary,
plus `product: {id, name, unit}`. History responses have no current quantity
or fabricated balance.

## Employees

Listing includes both roles and account states. Filters: `role`,
`is_active=0|1`, `search` (name or username).
The existing resource remains unchanged: id, name, username, role, is_active.

Information updates accept only name, username, role and password as optional
fields. Username stays unique. An omitted password is preserved; a supplied
password uses the existing policy (8 characters minimum, 72 bytes maximum,
no NUL characters) and Laravel hashing.

Status updates accept only `is_active=0|1`. Self-deactivation is rejected.
Updates lock user rows in primary-key order before checking that an active
ADMIN remains. This deliberately serializes employee mutations for this MVP
and protects concurrent demotions/deactivations without a new lock table.
Historical records remain untouched; existing tokens for inactive users are
rejected by the existing active-user middleware.

## Expenses

Create fields: name (maximum 150 characters), positive amount (up to
99999999.99, maximum two fractional digits), optional note, expense_date
(`YYYY-MM-DD`). Notes for expenses and movements support the existing TEXT
column limit of 65535 UTF-8 bytes. The server supplies creator and ACTIVE status.

Updates accept a subset of those four fields and preserve the creator.
Cancellation changes ACTIVE to CANCELLED under a row lock. Editing or cancelling
an already cancelled expense returns a 422 business error; the record remains.
Expense operations never write inventory or stock transactions, and purchases
never create expenses.

Listing includes both statuses. Filters: `from`, `to`, `status`,
`created_by`, `search` (name). Date bounds use expense_date and are inclusive;
either bound may be omitted. Results are ordered by expense_date descending,
then ID descending. Amounts are two-decimal strings; creators have ID/name only.

Lists remain unpaginated, consistent with the existing management APIs.
Invalid input returns Laravel's 422 validation response; missing bound resources
return 404. No migrations, models, seeders, packages or middleware were added.

## Postman

Use the existing `docs/postman/OrderAPI.postman_collection.json` collection.
Authentication contains existing Login and new Logout; Employees retains both
existing creation examples and adds list/update/status; Inventory and Expenses
contain four examples each. Both movement-history examples use the same route.

All new requests with bodies use multipart form-data. PATCH examples send
actual HTTP POST with a form-data field `_method=PATCH`; Laravel still registers
only PATCH update routes. New requests inherit Bearer authentication and set
Accept: application/json, leaving Content-Type/boundary to Postman.
GET, logout and cancel have no body.

Employee creation captures `employee_id`; expense creation captures
`expense_id`. Set `product_id` to an existing physical stocked product;
the inventory list chooses its first result only when the variable is empty.
Existing `base_url` and `token` variables are reused.

During a full collection run, Login's test script skips directly to the first
existing order request. Cancel Expense continues to Print Jobs, Dashboard and
Reports; the final report schedules Logout, which ends the run. This keeps the
token valid throughout the collection.
For individual Send operations, run Login first and Logout last.
The old creation examples retain their original fixed usernames; repeated
whole-collection runs need fresh fixtures, as before.

## Verification and file inventory

Commands:

```powershell
php artisan route:list --path=api -v
php vendor/bin/phpunit -c phpunit.mysql.xml --filter RemainingModulesTest
php vendor/bin/phpunit -c phpunit.mysql.xml
php tests/run-postman.php
```

Feature tests use the existing transaction/rollback convention.
The live runner creates a randomly named disposable MySQL database, migrates it
normally, runs Newman against two local PHP servers, verifies database effects
and concurrency, then removes only that database. It tests actual PHP multipart
parsing and method spoofing, concurrent OUT/IN movements, competing admin
demotions/deactivations and expense cancellation. Existing order concurrency
assertions remain in place.

Verified results: 55 focused tests / 392 assertions; full MySQL suite
154 tests / 1166 assertions; Newman 62 requests / 161 assertions;
44 live database and concurrency assertions. All passed. The live runner
removed its disposable database after verification.

Created:

- Controllers: InventoryController, ExpenseController.
- Requests: ListInventoryRequest, ListStockMovementsRequest,
  CreateStockMovementRequest, ListEmployeesRequest, UpdateEmployeeRequest,
  UpdateEmployeeStatusRequest, ListExpensesRequest, CreateExpenseRequest,
  UpdateExpenseRequest.
- Resources: InventoryResource, StockMovementResource, ExpenseResource.
- Services: EmployeeService, ExpenseService.
- Tests: tests/Feature/RemainingModulesTest.php,
  tests/Support/verify-remaining-live.php.
- Documentation: this file.

Modified:

- routes/api.php.
- app/Http/Controllers/Api/AuthController.php and EmployeeController.php.
- app/Services/AuthenticationService.php and InventoryService.php.
- docs/postman/OrderAPI.postman_collection.json.
- tests/run-postman.php.

Employee creation's service, request and resource are reused unchanged.
The existing order inventory methods are unchanged.
