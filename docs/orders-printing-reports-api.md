# Orders, print jobs, dashboard and reports

## Endpoints and access

| Method | Path | Access |
| --- | --- | --- |
| GET | /api/orders | Active ADMIN; EMPLOYEE sees own orders only |
| GET | /api/orders/{order} | Active ADMIN; EMPLOYEE sees own order only |
| GET | /api/print-jobs | Active ADMIN |
| POST | /api/print-jobs/{printJob}/retry | Active ADMIN |
| GET | /api/dashboard/summary | Active ADMIN |
| GET | /api/reports/financial | Active ADMIN |
| GET | /api/reports/top-products | Active ADMIN |

All seven routes use existing `auth:sanctum` and `active` middleware.
The last five also use `admin`. Employee visibility is enforced in the order
query, even with another employee_id filter. Unauthorized details return 403;
missing order/print-job IDs return 404.

## Validation and response choices

Order filters: order_status, payment_status, employee_id, table_id, from, to,
search (order_number). Print-job filters: status, job_type, printer_id,
department_id, order_id, from, to. Enum values match the existing schema;
identifiers must exist. Both lists filter created_at and sort by created_at
descending, then ID descending.

Orders and print jobs use standard Laravel resource pagination:
`data`, `links`, `meta`. Default per_page is 20; accepted range is 1–100.
Page must be a positive integer. Summary resources eagerly load only related
summaries, without loading order items.

Details reuse OrderResource and OrderItemResource. Existing flat fields remain;
employee, table and product summary objects are additive. All stored items,
including CANCELLED items, are returned. Saved prices, quantities and amounts
are preserved. Order workflow code is unchanged.

Query dates use YYYY-MM-DD; bounds are inclusive and must be ordered.
Financial and top-product reports require both dates. Dashboard defaults both
to today's configured application date only when neither is supplied. With one
bound supplied, the other remains null and unbounded.

Financial calculations use PAID, non-CANCELLED orders by paid_at and ACTIVE
expenses by expense_date. Daily order counts use created_at. The financial
report executes two grouped queries and fills calendar gaps in memory, so
zero-activity dates appear and daily amounts sum exactly to the response totals.
Database decimal sums and BCMath preserve two-decimal strings, including
negative net cash flow and aggregates larger than one row's column precision.

Top products sum ACTIVE items of PAID orders selected by paid_at, using saved
quantity and subtotal. Current product price/active flags do not affect results.
Ordering is quantity descending, then product ID ascending. Default limit is
10, maximum 100. Dashboard reuses this calculation with limit 5.

Dashboard table counts include active tables only and represent current state.
Recent active orders are the latest 10 orders outside CLOSED/CANCELLED,
regardless of the financial period. Every schema order status appears in the
period counts, including zeros.

## Timezone convention

Today's date and calendar iteration use `config('app.timezone')`; no city or
UTC constant is embedded in the report services. The existing application is
configured to UTC, which this change does not alter.

SQL date grouping/filtering follows the project's existing Eloquent/MySQL
wall-clock storage convention: timestamps are read through the same database
session timezone convention used when written. This preserves local calendar
dates without applying an additional offset to values already written in the
application timezone. Changing that storage convention or the application
timezone retroactively requires a separate data/timezone migration decision.
Tests include a non-UTC application date that differs from the current UTC date
and paid timestamps at the start and end of a local calendar day.

## Retry printing

Only FAILED jobs can be retried. An inactive printer, unsupported connection,
or invalid network IP/port produces a 422 business error before changing the job.
PENDING and PRINTED also return 422.

A short transaction locks the job and persists FAILED → PENDING. Network
attempts increment once during this claim. The transaction commits before
the stored payload is sent. Concurrent retry requests cannot claim a PENDING
job. Transmission success sets PRINTED and printed_at; failure sets FAILED
with a null printed_at. Both completed attempt outcomes return HTTP 200 with
the accurate job resource.

NetworkPrinterTransport contains the existing bounded TCP sender, extracted
from ReceiptPrintingService without changing its connection/write behavior.
Both original receipt printing and retry now use it. Retries never regenerate
payloads or call order/payment/inventory workflows.

LOCAL_AGENT and USB retry to PENDING without incrementing a physical attempt
or claiming success. The repository has pending-job storage but no implemented
external agent consumer. Physical printing for these connections still depends
on an external agent; this task adds no queue or agent infrastructure.

The existing three-state schema can represent a claim, but cannot distinguish
a crashed network retry from an agent job awaiting processing. A process crash
or failed final database write may leave PENDING after transmission. Recovery
requires operational verification before changing that state; automatically
resetting it could duplicate an already transmitted print. No automatic
recovery endpoint was added. PRINTED means successful TCP transmission under
the existing protocol, not a physical-paper acknowledgement.

## Postman

The existing collection is updated in place. Existing root order operations
are grouped into one Orders folder, alongside List Orders and Get Order Details.
Print Jobs, Dashboard and Reports contain the other five new requests.
Every new item is a real HTTP endpoint.

All seven requests have no body, including retry. They inherit the collection's
Bearer token and set Accept: application/json. Optional GET filters appear as
disabled entries in the Params tab. Required report dates use report_from and
report_to; their example defaults cover October 2026. Existing base_url already
includes /api, so it is not appended again. Order details reuse order_id.
Print-job listing captures a FAILED job into print_job_id and its current
attempt count when one is present; otherwise select a failed job manually.

The complete collection preserves every earlier request and assertion.
Its workflow now continues from Cancel Expense through Print Jobs, Dashboard
and Reports, then logs out. Retry examples can legitimately return FAILED if
the configured printer is unavailable; tests verify the reported state rather
than claiming physical success.

## Files

Created controllers under app/Http/Controllers/Api:

- DashboardController.php
- PrintJobController.php
- ReportController.php

Created FormRequests under app/Http/Requests:

- ListOrdersRequest.php
- ListPrintJobsRequest.php
- DashboardSummaryRequest.php
- FinancialReportRequest.php
- TopProductsReportRequest.php

Created resources under app/Http/Resources:

- OrderSummaryResource.php
- PrintJobResource.php
- DashboardSummaryResource.php
- FinancialReportResource.php
- TopProductResource.php

Created services under app/Services:

- OrderQueryService.php
- PrintJobService.php
- NetworkPrinterTransport.php
- DashboardService.php
- FinancialReportService.php

Created tests and documentation:

- tests/Feature/OrdersReportsTest.php
- tests/Support/verify-reports-live.php
- docs/orders-printing-reports-api.md

Modified:

- routes/api.php
- app/Http/Controllers/Api/OrderController.php
- app/Http/Resources/OrderResource.php
- app/Http/Resources/OrderItemResource.php
- app/Services/ReceiptPrintingService.php
- docs/postman/OrderAPI.postman_collection.json
- tests/run-postman.php
- docs/remaining-modules-api.md (collection run-order documentation only)

No migrations, schema changes, model changes, seeders, packages or new
middleware. Existing order mutation, payment and inventory services are
unchanged.

## Verification

```powershell
php artisan route:list --path=api -v
php vendor/bin/phpunit -c phpunit.mysql.xml --filter OrdersReportsTest
php vendor/bin/phpunit -c phpunit.mysql.xml
php tests/run-postman.php
```

Focused tests cover authorization, filters, eager loading, historical data,
retry state transitions and reentry, transaction boundaries, date semantics,
timezone boundaries, zero days, decimal aggregates, live dashboard sections
and top-product sorting/limits.

The live runner uses a disposable MySQL database, two PHP servers and a local
TCP test receiver. It verifies one connection and one saved payload for two
competing retries, with no changes to orders, payments, stock or stock history.
No physical printer is needed. Existing concurrency checks remain in place.

Verified results:

- Focused suite: 17 tests, 258 assertions.
- Full MySQL suite: 171 tests, 1424 assertions.
- Newman: 69 requests, 182 assertions.
- Live database/concurrency checks: 52 assertions.
- All passed; the disposable database was removed.
- Route audit: exactly seven additions, with the required middleware.
- All 62 existing Postman request definitions were preserved.
