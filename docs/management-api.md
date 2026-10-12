# Management APIs

All 15 endpoints use the existing `auth:sanctum`, `active`, and `admin` middleware. Login as an active ADMIN and send `Authorization: Bearer <token>`, `Accept: application/json`, and JSON request bodies. Creation returns 201; reads and updates return 200 with the existing `data` resource wrapper. Validation/business errors return 422, unauthenticated requests 401, forbidden requests 403, and missing records 404.

| Module | Routes |
| --- | --- |
| Categories | `GET /api/categories`, `GET /api/categories/{category}`, `POST /api/categories`, `PATCH /api/categories/{category}` |
| Departments | `GET /api/departments`, `POST /api/departments`, `PATCH /api/departments/{department}` |
| Printers | `GET /api/printers`, `POST /api/printers`, `PATCH /api/printers/{printer}` |
| Tables | `GET /api/tables`, `POST /api/tables`, `PATCH /api/tables/{table}` |
| Settings | `GET /api/settings`, `PATCH /api/settings/{key}` |

No delete, restore, bulk, or helper endpoints were added. Lists include active and inactive records and are unpaginated, matching existing collection behavior. Services own persistence/business rules; controllers only call services and return resources. Existing models and relationships are reused.

## Categories

Create: `{"name":"Desserts"}`. PATCH may contain `name` and/or boolean `is_active`. Names are required on creation, nonempty when supplied on update, strings of at most 100 characters, and unique using the database's existing collation. Updates ignore their own ID in uniqueness checks; the database unique-index race is also mapped to validation.

List optionally accepts `?is_active=0&search=Drinks` (boolean query values `0`/`1`; search is a literal substring). Detail includes both active and inactive associated products using eager loading and the existing ProductResource. Deactivation never changes or deletes products. Creation uses the schema's active-by-default behavior.

## Departments

Create: `{"name":"Kitchen","printer_id":3}`. Names follow the existing unique, 100-character schema. Printer is optional/nullable; PATCH can update name, printer, and activity. `printer_id:null` detaches it.

Only DEPARTMENT printers may be assigned; inactive department printers remain assignable because activity is a separate setting. List responses include a printer summary or null. Assignment locks the printer and rechecks its type so a concurrent printer edit cannot invalidate the relationship. Deactivation preserves products, printer links, and historical print jobs.

## Printers

```json
{
  "name": "Kitchen Printer",
  "printer_type": "DEPARTMENT",
  "connection_type": "NETWORK",
  "connection_config": "{\"ip\":\"192.168.1.20\",\"port\":9100}"
}
```

Name is required, maximum 100 characters. Printer types are exactly `DEPARTMENT` and `RECEIPT`. The schema has no connection-type CHECK constraint; validation uses the three types already recognized by the existing printing code: `NETWORK`, `USB`, `LOCAL_AGENT`. No new type is introduced.

Configuration is an optional nullable string, limited to the TEXT column's 65,535-byte capacity. Keep JSON configuration encoded as a string, as in the existing schema and receipt service. Existing raw-IP/`ip:port` formats remain accepted. Connection testing/dispatch is not performed by management endpoints.

PATCH supports all creation fields plus `is_active`. Deactivation retains department references and print jobs. A linked printer cannot be converted to RECEIPT; detach its departments first. This guard and department assignment share the printer-row lock. Existing receipt printing already selects active printers; department jobs are only queued for a future dispatcher, so printing services were not changed.

## Tables

Create: `{"table_number":"10","default_chairs":6}`. The actual schema stores `table_number` as a signed integer, not a label. Numeric strings are accepted and responses use integers; `10-A` is rejected. Table numbers must fit the existing signed INT and be unique. Default chairs are required on creation and nonnegative integers within INT capacity; PATCH may omit them but cannot set them to null.

New tables are active and AVAILABLE. Client `status` is explicitly forbidden, including null. PATCH supports only validated table number, default chairs, and activity fields. No management action changes order status, frees a table, or cancels an order.

List eagerly loads orders not CLOSED/CANCELLED. OCCUPIED tables include `active_order` with ID, number, order status, and payment status; AVAILABLE tables return null. Updates take the same table-row lock used by order creation. Deactivation fails with an Arabic business error if the table is OCCUPIED or has an active order, including a stale AVAILABLE flag with an active order. Other permitted metadata edits do not rewrite order snapshots.

## Settings

PATCH an existing key with `{"value":"20.00"}`, `{"value":"Karm Altin Rest House"}`, or `{"value":"+963999999999"}`. Values must be strings of at most 255 characters. Numeric-looking strings remain strings; JSON numbers, arrays, and null are rejected. Empty strings and whitespace are preserved because the schema permits them. Middleware trimming/null conversion is bypassed only for `/api/settings/*` requests to support this behavior.

There are no per-key or numeric validations, even for `chair_price`. The API does not create keys, accepts no replacement `key`, and returns 404 for an unknown key. Only `value` and its automatically managed `updated_at` change. Resources return only `key` and `value`.

## Postman

The existing collection now has Categories (4 requests), Departments (3), Printers (3), Tables (3), and Settings (2). All requests map to the actual routes above, inherit `{{token}}`, and use the existing `{{base_url}}` that already contains `/api`. Login and earlier folders remain unchanged.

Create requests capture IDs in `management_category_id`, `management_department_id`, `management_printer_id`, and `management_table_id`. Category creation precedes its detail request so the folder also runs against an empty categories table. Category/department names use Postman's GUID variable for repeatability. List Tables sets the next `management_table_number`; List Settings selects an existing `management_setting_key`. Update examples deactivate newly created records; set `is_active:true` to reactivate. Department's printer can be populated with an existing DEPARTMENT printer ID. Settings update uses `"20.00"` and changes the selected existing setting, so choose the intended key when running manually.

## Verification

```sh
php artisan route:list --path=api -vv
php vendor/bin/phpunit -c phpunit.mysql.xml --filter ManagementModulesTest
php vendor/bin/phpunit -c phpunit.mysql.xml
php tests/run-postman.php
```

Verified on 2026-09-26:

- New management tests: 20 cases / 263 assertions passed.
- Full MySQL suite: 99 tests / 774 assertions passed.
- Live Postman/Newman collection: 50 requests / 132 assertions passed.
- Existing runner's database/concurrency checks: 27 assertions passed; disposable database removed.
- Route authorization, eager-loading query counts, Pint formatting, and diff whitespace checks passed.

PHPUnit uses rollback fixtures; Newman uses the existing disposable-database runner. No migration or database reset is required. Existing unrelated workflow behavior and previously uncommitted work were preserved.

## Files

- Controllers: `CategoryController`, `DepartmentController`, `PrinterController`, `RestaurantTableController`, `SettingController` in `app/Http/Controllers/Api`.
- Requests: `ListCategoriesRequest`, `CreateCategoryRequest`, `UpdateCategoryRequest`, `CreateDepartmentRequest`, `UpdateDepartmentRequest`, `CreatePrinterRequest`, `UpdatePrinterRequest`, `CreateTableRequest`, `UpdateTableRequest`, `UpdateSettingRequest` in `app/Http/Requests`.
- Services: `CategoryService`, `DepartmentService`, `PrinterService`, `RestaurantTableService`, `SettingService` in `app/Services`.
- Resources: `CategoryResource`, `CategoryDetailsResource`, `DepartmentResource`, `PrinterResource`, `RestaurantTableResource`, `SettingResource` in `app/Http/Resources`.
- Tests: `tests/Feature/ManagementModulesTest.php`.
- Modified existing files: `routes/api.php`, `bootstrap/app.php` (settings-string middleware exceptions only), `docs/postman/OrderAPI.postman_collection.json`.
- Documentation: this file. No migrations, models, seeders, existing tests, or existing service files were changed.

Schema observations intentionally retained: table labels remain integer-valued, and connection types are constrained in application validation rather than adding a new database CHECK. Existing pending department print jobs still await a dispatcher; no new physical printing mechanism was added.
