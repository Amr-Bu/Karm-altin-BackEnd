# Create employee/user

`POST /api/employees` uses the existing `auth:sanctum`, `active`, and `admin` middleware. Only active administrators may create either supported role. Login remains unchanged: obtain a token from `POST /api/login`, then send `Authorization: Bearer <token>` and JSON headers.

```json
{
  "name": "Ahmad Ali",
  "username": "ahmad",
  "password": "StrongPassword123",
  "role": "EMPLOYEE"
}
```

For an administrator, use `"name": "System Admin"`, `"username": "admin2"`, and `"role": "ADMIN"`.

Successful creation returns HTTP 201:

```json
{
  "data": {
    "id": 10,
    "name": "Ahmad Ali",
    "username": "ahmad",
    "role": "EMPLOYEE",
    "is_active": true
  }
}
```

- `name`: required string, maximum 100 characters.
- `username`: required string, maximum 100 characters, unique according to the existing users-table collation.
- `password`: required string, minimum 8 characters, maximum 72 bytes; null bytes are rejected. The maximum avoids bcrypt truncation. Existing login rules are unchanged.
- `role`: required, exactly `ADMIN` or `EMPLOYEE`, using the model constants.
- Client-supplied `id`, `is_active`, `password_hash`, timestamps, `remember_token`, `token`, and `tokens` are rejected even when null. Other unvalidated fields are ignored and never passed to persistence.

Validation failures use Laravel's existing 422 `message`/`errors` format. Unauthenticated requests return 401; employees and inactive users return 403. Concurrent duplicate inserts are also mapped to a username validation error.

`CreateEmployeeRequest` validates input. `EmployeeController` passes validated data to `EmployeeCreationService` and returns `EmployeeResource`. The service hashes with `Hash::make`, explicitly selects persisted fields, and activates the new account. The resource exposes only the five fields shown above. No token is issued by this endpoint. The existing users schema already supports everything; no migration, model, authentication, or workflow changes are needed.

The eight-character minimum is a new-user policy choice because the existing login request specifies no minimum. Active-by-default follows the existing schema. Administrators may create administrators as explicitly requested.

## Postman

The existing `docs/postman/OrderAPI.postman_collection.json` now contains an `Employees` folder with `Create Employee` and `Create Admin`. Run the existing Login request as an ADMIN first; its token capture and inherited Bearer authentication are preserved. `base_url` already includes `/api`, so both requests use `{{base_url}}/employees`.

The examples use `ahmad` and `admin2`. Select unused usernames when running manually; repeating a successful request returns 422. Each request checks 201, the exact public response fields, matching username/role, active status, and absence of authentication secrets.

## Tests

```sh
php vendor/bin/phpunit -c phpunit.mysql.xml --filter CreateEmployeeTest
php vendor/bin/phpunit -c phpunit.mysql.xml
php tests/run-postman.php
php artisan route:list --path=api/employees -vv
```

The new MySQL configuration runs both existing and new tests. Feature fixtures use rollback transactions, matching the existing suite; no reset or migration is performed by PHPUnit. The existing Postman runner uses a disposable database and removes it afterward.

The default `phpunit.xml` uses SQLite and the existing domain migrations contain MySQL-specific constraints/triggers. That existing setup is unchanged; use `phpunit.mysql.xml` for the complete database-backed suite.

Verified on 2026-09-25: 30 employee test cases / 122 assertions passed; full MySQL suite 47 tests / 284 assertions passed. Newman successfully loaded the updated collection and passed all 25 HTTP requests / 51 assertions, including both new creation examples. The existing runner's 27 database/concurrency assertions also passed and its temporary database was removed. Route middleware, Pint, and diff whitespace checks passed; the existing collection entries, variables, and authentication were preserved unchanged.
