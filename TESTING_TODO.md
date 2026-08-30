# Automated Testing Roadmap

This checklist tracks application behavior that still needs automated coverage. Backend API tests use Laravel PHPUnit Feature tests unless noted otherwise. Each domain should cover success, validation/failure, authorization, and important side effects without relying on production data.

## Completed

- [x] Test foundation, SQLite migration bootstrap, shared `TestCase` helpers, role-aware user factory states
- [x] Authentication basics: login, invalid credentials, logout, API-key and cookie auth
- [x] Health endpoint and public bootstrap: languages, translations, public config, site-token enforcement
- [x] Public reservations and validation rules
- [x] Waitlist creation, duplicate protection, admin listing, moderator denial
- [x] Admin users: listing, creation, token rotation, last-admin protection
- [x] Events and locations: creation, timezone conversion, upcoming events, validation
- [x] Admin settings: read, update, cache refresh, export, import
- [x] Email templates: creation, listing, preview, clone, validation
- [x] Event triggers: creation, listing, validation, toggle, clone
- [x] RSS feeds and scheduled-task dashboard baseline
- [x] Playwright frontend shell and CI-backed backend health smoke tests

## P0: Data And Automation Safety

### Authentication And Authorization

- [ ] Two-factor API: enable, QR, recovery codes, confirmation, disable, status
- [x] Admin two-factor operations: enable, disable, reset, and moderator denial
- [ ] Self-service two-factor status, QR, recovery, enable/confirm/disable with provider mock
- [ ] Representative authorization matrix for unauthenticated, guest, user, moderator, admin, and superadmin roles
- [ ] Login edge cases: inactive users, name identifier, remember cookie, invalid OTP, recovery code

### Reservations And Waitlist

- [x] Admin reservation listing, update, delete, export, and moderator denial
- [x] Reservation undo by token and disabled-undo behavior
- [ ] Admin reservation filters/notification defaults and authenticated undo
- [ ] Capacity-full reservation to waitlist transition
- [ ] Waitlist promotion, cancellation, undo, export, and limit enforcement
- [ ] Email validation and admin approval flows for reservations and waitlist entries

### Scheduled Tasks And Action Lists

- [x] Scheduled-task creation validation, cron next-run parsing, run-now queue dispatch
- [ ] Scheduled-task CRUD, activation/deactivation, clone, due-task execution, and failure history
- [x] Action-list creation with actions and action CRUD/delete behavior
- [ ] Action-list update/clone, active/moderation flags, enabled/disabled execution behavior
- [ ] Action-list execution for email, webhook, setting-change, archive, and purge actions
- [ ] Scheduler authorization and moderation dashboard public URL

### Data Integrity And Privacy

- [x] Data portability table/file/operation listing
- [ ] Data portability backup and restore integrity
- [x] Data portability transport preflight and transport-profile security
- [ ] Data portability create/receive transport and failure handling
- [x] Archive creation and archive reservation/waitlist contents
- [x] Archive filtering, reservation restore, and anonymization authorization
- [ ] Archive waitlist restore, CSV export, and superadmin anonymization behavior
- [ ] Purge-all authorization, confirmation, and data removal safety

## P1: Core Feature Coverage

### Surveys And Forms

- [x] Survey creation and question creation
- [ ] Survey CRUD, preview, activation, date boundaries, and global-question CRUD
- [x] Public survey display, submission, duplicate response protection, and required-response validation
- [ ] Public survey status check, date boundaries, results, and export
- [ ] Survey response listing, results, and export
- [ ] Form-field CRUD and public visibility/ordering
- [ ] FAQ CRUD and public FAQ behavior
- [ ] Custom placeholder CRUD and replacement behavior

### Rules, Triggers, And Integrations

- [x] Validation-rule CRUD, operator validation, configuration, reorder, and available fields
- [ ] Validation-rule webhook-template integration and all condition-type edge cases
- [ ] Event-trigger update, delete, simulate, cooldown/delay behavior
- [ ] Webhook-template CRUD, clone, test, HTTP fake success/failure
- [ ] Action-list webhook/email execution with fakes
- [ ] Placeholder service coverage for context, recipient, event, reservation, and runtime tokens

### Email And Mail Transport

- [x] Email validation listing/filtering, discard, invalid-token, and moderator access
- [ ] Email validation token verification and admin approval/resend success flows
- [ ] Email template deletion and attachment/iCal associations
- [ ] Email broadcast queue/mail assertions
- [ ] Mail account CRUD and connection-test failure/success fakes
- [ ] Mail group CRUD, account priority, and connection tests
- [ ] Email blacklist-domain CRUD and enforcement
- [ ] Attachment-template CRUD, upload, download, and delete
- [ ] iCal-template CRUD, clone, preview, and generated output

### Admin And Operational APIs

- [ ] Media image listing and access controls
- [ ] Placeholder listing and resolved values
- [x] Audit-log listing, count, filters, clear behavior, and role protection
- [x] Worker-stat response contract and role protection
- [ ] Diagnostics snapshots, cleanup, Redis-key diagnostics, and worker heartbeat behavior
- [ ] Email-validation rate-limit listing/deletion
- [ ] Rate-limit diagnostics

## P2: Frontend And Quality Gates

### Browser Workflows

- [ ] Playwright admin login and logout
- [ ] Playwright public reservation submission
- [ ] Playwright moderator dashboard and action-list execution
- [ ] Playwright admin event creation/update
- [ ] Playwright settings update/export/import
- [ ] Playwright session-expiry redirect
- [ ] Playwright 2FA setup flow
- [ ] Playwright data export/download flow

### Frontend Unit Coverage

- [ ] Add Vitest only if component/API-unit coverage becomes necessary
- [ ] Axios request interceptor: site token and credentials
- [ ] Axios response interceptor: expired-session event
- [ ] `adminApi` route-prefix and auth behavior
- [ ] Public API error handling and translation fallback
- [ ] Critical composables and utility functions

### CI And Quality Gates

- [ ] Run backend tests and Playwright smoke tests on `main`, `master`, `dev`, and `vX.Y.Z` branches
- [ ] Upload backend failure logs and Playwright reports
- [ ] Add coverage reporting after representative domain coverage exists
- [ ] Add PHP formatting/static analysis and frontend lint/type checks
- [ ] Document focused commands for every test domain

## Definition Of Done For A Domain

- [ ] Happy path is covered
- [ ] Validation and failure behavior is covered
- [ ] Authorization is covered for the relevant roles
- [ ] External effects use deterministic fakes or assertions
- [ ] Test data is isolated with `RefreshDatabase` where needed
- [ ] Focused test file passes
- [ ] Full backend suite passes
- [ ] Checklist item is marked complete with the test file path
