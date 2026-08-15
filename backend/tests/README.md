# Backend tests

The backend test suite uses Laravel HTTP/Feature tests and PHPUnit unit tests.
The test environment is configured in `phpunit.xml` with an in-memory SQLite database,
array cache/session/mail stores, and synchronous queues.

## Run tests

From `backend/`:

```bash
composer test
```

Run a focused slice:

```bash
php artisan test tests/Feature/ApiAuthenticationTest.php
php artisan test tests/Feature/ReservationValidationRuleTest.php
php artisan test tests/Unit/ValidationRuleEngineTest.php
```

## Adding API tests

1. Add the test under `tests/Feature/<Domain>/`.
2. Use `RefreshDatabase` for tests that write database state.
3. Use `createApiUser('admin')`, `createApiUser('moderator')`, or another supported role for API-key tests.
4. Use `apiHeaders($user)` for `X-Api-Key` requests.
5. Use `withCredentials()->withUnencryptedCookie('api_session', $token)` for cookie-authenticated JSON requests.
6. Use `validSiteToken()` for public site-token routes.
7. Prefer assertions on status, important JSON fields, and response structure over complete payload snapshots.

Shared helpers live in `tests/TestCase.php`. Role states live in
`database/factories/UserFactory.php`.

## Isolation

Tests must pass independently and in a different order. Use Laravel fakes for mail,
queues, notifications, HTTP webhooks, and external transports where the behavior under
test does not require the real service.
