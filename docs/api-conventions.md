# API conventions

Every endpoint follows these rules. A new endpoint that breaks one is a bug.

## Basics
- Base path: `/api/v1`. Breaking changes go to `/api/v2`, never into v1.
- Everything is JSON, even without an `Accept` header (`ForceJsonResponse` middleware + the exception renderer).
- JSON keys are `snake_case`.
- The only unwrapped response is `GET /api/v1/ping` -> `{"status":"ok"}` (infrastructure check, no data).

## Status codes
| Code | Use |
|---|---|
| 200 | Read, or update that returns the resource |
| 201 | Created (return the new resource) |
| 204 | Deleted (empty body) |
| 401 | Not logged in |
| 403 | Logged in but not allowed |
| 404 | Not found (also use it for resources the user must not know exist) |
| 405 | Wrong HTTP method (set by the router) |
| 409 | Conflict (duplicate, out of stock, illegal state change) |
| 422 | Invalid input |
| 429 | Too many requests (`Retry-After` header is included) |
| 500 | Unexpected server error |

## Errors
One shape for every error, produced only by `App\Exceptions\ApiExceptionRenderer` (registered in `bootstrap/app.php`):

```json
{
  "message": "The name field is required.",
  "code": "validation_failed",
  "errors": { "name": ["The name field is required."] }
}
```

- `message`: human readable, safe to show.
- `code`: stable machine code. Clients switch on `code`, never on `message`.
- `errors`: field -> list of messages. Always present; `{}` when there are no field errors.

| Status | `code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` |
| 419 | `csrf_token_mismatch` |
| 422 | `validation_failed` |
| 429 | `too_many_requests` |
| 500 | `server_error` |
| 503 | `service_unavailable` |

Throw, don't build errors by hand: `abort(409, 'Not enough stock.')`, `Gate::authorize(...)`, `findOrFail()`. Domain rules that need their own `code` get an exception class that extends `HttpException`; add the code to the table above.

**500s:** in every environment except local debugging the message is always `Server error.`. Stack traces, exception class names and framework messages are never returned. They go to the log. 404 and 405 messages are fixed for the same reason (framework messages leak model and route names).

## Validation
Only in Form Requests (`app/Http/Requests`). Never `$request->validate()` or `Validator::make()` in a controller.

## Responses
- Every response body is built by an API Resource (`app/Http/Resources`). Never return a model or an Eloquent collection.
- Resources expose an explicit list of fields. Never `parent::toArray()` on a model.
- Single item is wrapped in `data`:

```json
{ "data": { "id": 1, "name": "Mug" } }
```

- Lists are `data` + `meta` + `links` (use `Resource::collection($paginator)`):

```json
{
  "data": [ { "id": 1 } ],
  "links": { "first": "...?page=1", "last": "...?page=3", "prev": null, "next": "...?page=2" },
  "meta": { "current_page": 1, "per_page": 15, "total": 40, "last_page": 3 }
}
```

## Pagination
Page based: `?page=2`. Optional `?per_page=` with a max of 100 (validated in the Form Request). Always `paginate()`, never `get()` on a list endpoint.

## Dates
ISO 8601, UTC, whole seconds: `2026-10-29T09:54:00Z`. Set globally in `AppServiceProvider` (`Carbon::serializeUsing`). Resources pass Carbon objects through; never format dates by hand.

## Money (D1)
Integer minor units plus the currency, always together:

```json
{ "price": 1999, "currency": "USD" }
```

Never floats, never strings like `"19.99"`. The frontend formats it.

## Checklist for a new endpoint
1. Route in `routes/api.php` (the `/api/v1` prefix is automatic).
2. Form Request for input, Resource for output.
3. Policy or Gate for authorization.
4. Right status code from the table above.
5. Pest tests: success, 401/403 where relevant, 422, and one edge case.
