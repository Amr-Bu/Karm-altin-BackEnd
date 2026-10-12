# Products & Recipes API

All routes use existing `auth:sanctum` and `active` middleware. Management routes additionally use `admin`. Controllers delegate to services; requests validate input; resources return the existing `data` wrapper. No helper/service operations are exposed as routes.

| Method | Route | Access | Success |
| --- | --- | --- | --- |
| GET | `/api/products` | ADMIN | 200 |
| GET | `/api/catalog` | Active authenticated user | 200 |
| GET | `/api/products/{product}` | ADMIN | 200 |
| POST | `/api/products` | ADMIN | 201 |
| PATCH | `/api/products/{product}` | ADMIN | 200 |
| PATCH | `/api/products/{product}/status` | ADMIN | 200 |
| POST | `/api/products/{product}/ingredients` | ADMIN | 201 |
| PATCH | `/api/products/{product}/ingredients/{ingredient}` | ADMIN | 200 |
| DELETE | `/api/products/{product}/ingredients/{ingredient}` | ADMIN | 204 |

Validation/business failures return 422, unauthenticated requests 401, forbidden requests 403, and missing products or recipe rows under the wrong parent 404.

## Lists and catalog

`GET /api/products` returns all types and both activity states, with category/department summaries and without recipes. It accepts:

```text
?item_type=RAW_MATERIAL
?item_type[]=RAW_MATERIAL&item_type[]=PURCHASED_PRODUCT
?category_id=2&department_id=1&is_active=0&search=Cola
```

`is_active` query values use `0`/`1`. Search matches a literal name substring. Category and department filters must refer to existing rows. Lists are unpaginated, matching the requested all-products behavior and existing collection style.

The catalog eagerly loads active categories and active sellable products (`PRODUCED_PRODUCT`, `PURCHASED_PRODUCT`) with departments. Active empty categories remain visible. Uncategorized products do not belong to a category group. Product details include `ingredients`; each component's unit is read from its referenced product.

## Product creation and updates

Examples:

```json
{"name":"Chicken","item_type":"RAW_MATERIAL","unit":"KG","min_stock":"5.000","is_stock_tracked":true}
```

```json
{"name":"Cola","item_type":"PURCHASED_PRODUCT","unit":"PIECE","price":"10.00","min_stock":"20.000","category_id":3,"department_id":2,"is_stock_tracked":true}
```

```json
{"name":"Crispy Meal","item_type":"PRODUCED_PRODUCT","unit":"PIECE","price":"50.00","category_id":2,"department_id":1,"is_stock_tracked":true}
```

Replace category/department IDs with existing values, or use null. Name is required, maximum 150 characters. Type and unit must match existing model/schema values. Supported units are `KG`, `G`, `LITER`, `ML`, `PIECE`. Sellable price is required and nonnegative, maximum `99999999.99`, with at most two decimals. Minimum stock is nullable, nonnegative, maximum `999999999.999`, with at most three decimals. Tracking defaults to true; new products default to active. Activity is managed separately through the status route.

Normalization applies to create and update:

- RAW_MATERIAL: price, category, and department become null even if the full form supplies irrelevant values. Creates an inventory row with `0.000`.
- PURCHASED_PRODUCT: retains sellable fields and creates an inventory row with `0.000`.
- PRODUCED_PRODUCT: minimum stock becomes null; no inventory row is created. Recipe configuration is separate and initially may be empty.

Creation is atomic and produces no stock ledger movement. Tracking false still preserves the stock row for raw/purchased products; subsequent toggles never remove or reset balances/history. A missing raw/purchased inventory row is initialized at zero during update. Existing produced-product stock rows, if introduced by older code, are not destructively removed.

PATCH accepts the editable creation fields but rejects any `item_type` key, including the existing value. Units may change. There is no automatic unit conversion of existing balances or history. Protected IDs, timestamps, inventory balances, and nested recipes are rejected. Unknown unvalidated fields are ignored. PATCH status accepts only validated `is_active`; it never deletes products or references.

## Recipes and inventory integration

Add a component:

```json
{"product_id":7,"quantity_required":"0.250"}
```

The URL identifies the produced parent; body `product_id` identifies the component. The database retains `raw_material_id` and the existing `rawMaterial` relationship. Only RAW_MATERIAL or PURCHASED_PRODUCT can be components. The parent must be PRODUCED_PRODUCT. Quantity must be positive, fit `(12,3)`, and have at most three decimal places. Duplicate components fail validation; the database unique constraint also protects concurrent inserts. Recipe writes lock the parent and run inside a transaction.

Update accepts only `quantity_required`. Neither component identity nor unit can be supplied. Ingredient lookup is scoped to the URL parent. DELETE hard-deletes only the recipe row. These APIs never rewrite stock transactions or historical order-item prices.

The existing inventory calculation already deducts stock using the referenced component ID. Integration tests verify a meal consumes both Chicken and Cola and cancellation reverses its actual ledger consumption even after a recipe edit/deletion. A minimal guard now rejects empty/invalid stock-tracked recipes during inventory calculation, preventing approval from silently consuming nothing. Such products can still be configured or appear in pending orders; approval fails atomically until their recipe is valid. Existing non-stock-tracked behavior remains unchanged.

Existing limitation retained: the order schema has no per-item recipe or unit snapshots. Approval uses the then-current recipe, and the existing preparing-order quantity-edit service also uses the current recipe. Historical ledger rows remain unchanged; cancellation uses net ledger history. This module does not introduce recipe-version storage or redesign preparing-order edits. Changing recipes/tracking during preparation needs that separate snapshot design for exact historical per-item returns.

## Migration

`2026_09_26_000001_allow_purchased_recipe_components.php` adds replacement BEFORE INSERT/UPDATE guards allowing RAW_MATERIAL or PURCHASED_PRODUCT components, while retaining the produced-parent requirement and unique constraint. It installs the new guards before dropping the old guards, preserves all existing recipe rows, and leaves historical migration files untouched. Interrupted upgrades can be retried.

Rollback restores the old raw-only guards. If purchased components are still referenced, rollback refuses with a clear error instead of deleting data or leaving incompatible references. Remove those references intentionally before retrying. Upgrade, guarded rollback, successful rollback, and re-upgrade are verified in a disposable database by `tests/verify-recipe-migration.php`.

## Postman and tests

The existing collection has one new **Products & Recipes** folder with exactly ten requested examples (filtered listing shares the same GET route). It inherits `{{token}}`; `{{base_url}}` already includes `/api`. Login remains unchanged.

List requests capture existing produced/component IDs for the detail example. Create captures a new `recipe_product_id`; Add captures `recipe_ingredient_id`. The folder also uses `recipe_component_id`. Set those IDs manually when executing requests out of order. On an empty database, create a raw/purchased component and a produced product first using the same Create Product endpoint. The status example deactivates; change its boolean to reactivate.

```sh
php artisan migrate
php artisan route:list --path=api -vv
php vendor/bin/phpunit -c phpunit.mysql.xml --filter ProductsRecipesTest
php vendor/bin/phpunit -c phpunit.mysql.xml
php tests/verify-recipe-migration.php
php tests/run-postman.php
```

Feature tests use the existing rollback-transaction MySQL convention. The migration verifier and live Postman runner use separate randomly named databases that are deleted afterward. No `migrate:fresh` is used.

Verification on 2026-09-26: the full MySQL suite passed 79 tests / 510 assertions. A subsequent recipe-ID serialization regression check passed the module's 32 tests / 227 assertions. The final Postman/Newman run passed all 35 requests / 80 assertions, plus the existing runner's 27 database/concurrency assertions. Trigger upgrade/rollback verification passed 9 assertions. Route middleware, eager-loading query counts, Pint, and whitespace checks passed. Only the new migration ran on the application database.

## File inventory

- Controllers: `ProductController.php`, `RecipeIngredientController.php` in `app/Http/Controllers/Api`.
- Requests: `ListProductsRequest.php`, `CreateProductRequest.php`, `UpdateProductRequest.php`, `UpdateProductStatusRequest.php`, `AddRecipeIngredientRequest.php`, `UpdateRecipeIngredientRequest.php` in `app/Http/Requests`.
- Resources: `ProductResource.php`, `ProductDetailsResource.php`, `RecipeIngredientResource.php`, `CategoryCatalogResource.php` in `app/Http/Resources`.
- Services: new `ProductService.php`, `RecipeService.php`; minimal validation guard in existing `InventoryService.php`.
- New migration: `database/migrations/2026_09_26_000001_allow_purchased_recipe_components.php`.
- Tests: `tests/Feature/ProductsRecipesTest.php`, `tests/verify-recipe-migration.php`.
- Other changes: routes in `routes/api.php`, existing `docs/postman/OrderAPI.postman_collection.json`, and this document.

Existing models, seeders, historical migrations, authentication, employee endpoint, payment, printing, and order services remain unchanged.
