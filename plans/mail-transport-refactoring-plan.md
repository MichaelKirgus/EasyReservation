# Mail Transport Refactoring Plan

## Overview

This document outlines the architecture and implementation plan for refactoring the mail settings system in EasyReservation. The goal is to replace the current single SMTP configuration with a flexible, multi-account transport system that supports rate limiting, failover, and modern authentication methods.

---

## Current State Analysis

### Existing Implementation
- **Location**: [`AdminSettings.vue`](frontend/src/components/AdminSettings.vue:1) - Email tab contains mail settings
- **Current Settings** (in `settingsFields.js`):
  - `mail_host`, `mail_port`, `mail_username`, `mail_password`
  - `mail_encryption` (TLS/SSL)
  - `mail_from_address`, `mail_from_name`
  - `mail_global_cc`, `mail_global_bcc`

### Backend
- **EmailService.php**: Handles email sending with template resolution
- **SendMailJob.php**: Queue job that dispatches emails using configured mailer
- Current system uses a single global SMTP configuration

---

## New Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Mail Transport System                            │
├─────────────────────────────────────────────────────────────────────┤
│  ┌──────────────────┐      ┌──────────────────┐                    │
│  │  Transport Group │◄────►│  Transport Group │                    │
│  │   (Rate Limit)   │      │    (Failover)    │                    │
│  └────────┬─────────┘      └──────────────────┘                    │
│           │                                                         │
│    ┌──────┴──────┐                                                 │
│    ▼             ▼                                                 │
│  ┌─────────┐   ┌─────────┐                                        │
│  │Account 1│   │Account 2│   ...  (Multiple accounts per group)   │
│  └─────────┘   └─────────┘                                        │
│                                                                     │
│  Each Account:                                                     │
│  - SMTP Server Config                                              │
│  - Authentication                                                  │
│  - TLS/SSL Options                                                 │
│  - Rate Limit Settings                                             │
└─────────────────────────────────────────────────────────────────────┘
                              ▲
                              │
                    ┌─────────┴──────────┐
                    │   Email Templates  │
                    │  (Select Group)    │
                    └────────────────────┘
```

---

## Database Schema

### New Tables

#### `mail_transport_accounts` - Individual mail account configurations
```sql
CREATE TABLE mail_transport_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,              -- Display name for the account
    host VARCHAR(255) NOT NULL,              -- SMTP host
    port INT NOT NULL DEFAULT 587,           -- SMTP port (587 for TLS, 465 for SSL)
    encryption ENUM('tls', 'ssl', 'none') NOT NULL DEFAULT 'tls',
    username VARCHAR(255),                   -- SMTP username
    password TEXT,                           -- Encrypted password
    auth_method ENUM('plain', 'login', 'crammd5', 'oauth2_exchange', 'oauth2_google') DEFAULT 'plain',
    ignore_self_signed BOOLEAN DEFAULT 0,    -- Skip SSL certificate validation
    timeout INT DEFAULT 30,                  -- Connection timeout in seconds
    
    -- Modern Auth (OAuth2)
    oauth2_client_id VARCHAR(255),
    oauth2_client_secret TEXT,
    oauth2_refresh_token TEXT,
    oauth2_access_token TEXT,
    oauth2_token_expiry DATETIME NULL,
    
    -- Rate limiting per account
    rate_limit_per_minute INT DEFAULT 60,    -- Max emails per minute
    rate_limit_per_hour INT DEFAULT 1000,    -- Max emails per hour
    
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### `mail_transport_groups` - Transport groups with rate limits and failover
```sql
CREATE TABLE mail_transport_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,              -- Group name (e.g., "Primary SMTP", "Fallback")
    description TEXT,
    
    -- Rate limiting for the entire group
    rate_limit_per_minute INT DEFAULT 60,
    rate_limit_per_hour INT DEFAULT 1000,
    
    -- Failover strategy
    failover_strategy ENUM('sequential', 'round_robin', 'random') DEFAULT 'sequential',
    max_retries_per_account INT DEFAULT 3,
    
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### `mail_group_accounts` - Pivot table for many-to-many relationship
```sql
CREATE TABLE mail_group_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    priority INT DEFAULT 0,                  -- For sequential failover (lower = primary)
    
    FOREIGN KEY (group_id) REFERENCES mail_transport_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES mail_transport_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_group_account (group_id, account_id)
);
```

#### `mail_template_transport_groups` - Link templates to transport groups
```sql
CREATE TABLE mail_template_transport_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES mail_transport_groups(id) ON DELETE CASCADE,
    UNIQUE KEY unique_template_group (template_id, group_id)
);
```

---

## API Endpoints

### Mail Transport Accounts
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/mail-accounts` | List all accounts |
| POST | `/api/admin/mail-accounts` | Create new account |
| PUT | `/api/admin/mail-accounts/{id}` | Update account |
| DELETE | `/api/admin/mail-accounts/{id}` | Delete account |
| GET | `/api/admin/mail-accounts/{id}/test` | Test account connection |

### Mail Transport Groups
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/mail-groups` | List all groups |
| POST | `/api/admin/mail-groups` | Create new group |
| PUT | `/api/admin/mail-groups/{id}` | Update group |
| DELETE | `/api/admin/mail-groups/{id}` | Delete group |
| GET | `/api/admin/mail-groups/{id}/test` | Test group (try all accounts) |

### Email Templates
| Method | Endpoint | Description |
|--------|----------|-------------|
| PUT | `/api/admin/email-templates/{id}/transport-group` | Assign transport group to template |

---

## Frontend Components

### New Pages

#### 1. Mail Transport Management (`AdminMailTransports.vue`)
**Location**: `/admin/mail-transports`

**Features**:
- Tabbed interface: Accounts and Groups
- Account management table with CRUD operations
- Group management table with account assignment
- Test connection functionality
- Rate limit visualization

**Sub-components**:
- `MailAccountForm.vue` - Form for creating/editing accounts
- `MailGroupForm.vue` - Form for creating/editing groups
- `TransportTable.vue` - Generic table component for lists

#### 2. Mail Transport Settings Page
**Location**: New page in Administration section (separate from general settings)

**Navigation**: Add "Email transport" link in admin sidebar

---

## Implementation Steps

### Phase 1: Backend Setup
1. Create database migrations for new tables
2. Create Eloquent models with relationships
3. Implement API controllers for accounts and groups
4. Update EmailService to use transport groups
5. Add rate limiting logic

### Phase 2: Frontend - Accounts Management
1. Create `AdminMailTransports.vue` component
2. Implement account list view with table
3. Create `MailAccountForm.vue` for CRUD operations
4. Add test connection functionality
5. Implement form validation and error handling

### Phase 3: Frontend - Groups Management
1. Implement group list view
2. Create `MailGroupForm.vue` with account selection
3. Add priority/ordering for failover accounts
4. Implement rate limit settings UI

### Phase 4: Email Template Integration
1. Update email template management to include transport group selector
2. Modify template form to show available groups
3. Implement save logic for group assignment

---

## Mail Account Configuration Options

### SMTP Settings
| Field | Type | Description |
|-------|------|-------------|
| Host | Text | SMTP server address (e.g., smtp.gmail.com) |
| Port | Number | SMTP port (587=TLS, 465=SSL, 25=unencrypted) |
| Encryption | Dropdown | TLS, SSL, or None |
| Timeout | Number | Connection timeout in seconds |

### Authentication
| Field | Type | Description |
|-------|------|-------------|
| Auth Method | Dropdown | Plain, Login, CRAM-MD5, OAuth2 (Exchange), OAuth2 (Google) |
| Username | Text | SMTP username/email |
| Password | Secret | SMTP password or app-specific password |

### Modern Auth Support
| Field | Type | Description |
|-------|------|-------------|
| Client ID | Text | OAuth2 client ID |
| Client Secret | Secret | OAuth2 client secret |
| Refresh Token | Secret | OAuth2 refresh token (stored encrypted) |
| Access Token | Secret | OAuth2 access token (auto-refreshed) |

### TLS/SSL Options
| Field | Type | Description |
|-------|------|-------------|
| Ignore Self-Signed Certificates | Checkbox | Skip SSL certificate validation for self-signed certs |

---

## Rate Limiting Implementation

### Per-Account Limits
- `rate_limit_per_minute`: Maximum emails per minute
- `rate_limit_per_hour`: Maximum emails per hour
- Uses Redis or database for tracking

### Group-Level Limits
- Aggregates limits across all accounts in group
- Round-robin distribution between accounts
- Sequential failover when account reaches limit

### Rate Limit Tracking
```php
// Store rate limit data
rate_limit_data: {
    account_id: {
        minute_count: int,
        hour_count: int,
        last_reset_minute: timestamp,
        last_reset_hour: timestamp
    }
}
```

---

## Failover Strategy

### Sequential (Default)
1. Try Account 1 (highest priority)
2. If fails, try Account 2
3. Continue until success or all accounts exhausted

### Round Robin
- Distribute emails evenly across accounts
- Each account gets ~equal share of emails

### Random
- Select random account from group
- Provides load balancing without tracking

---

## Migration Strategy

### Step 1: Backward Compatibility
- Keep existing `mail_*` settings in database
- Create default transport account from existing settings
- Migrate global CC/BCC to transport groups

### Step 2: Gradual Migration
- New installations use new system
- Existing installations can migrate via admin interface
- Settings page shows migration status

---

## Testing Strategy

### Unit Tests
- Test mail account validation
- Test rate limiting logic
- Test failover strategies

### Integration Tests
- Test SMTP connection with various configurations
- Test email sending through transport groups
- Test rate limit enforcement

### Manual Testing Checklist
- [ ] Create new mail account with TLS
- [ ] Test connection to SMTP server
- [ ] Create transport group with multiple accounts
- [ ] Assign transport group to email template
- [ ] Send test email using template
- [ ] Verify rate limiting works
- [ ] Test failover when primary account fails

---

## Security Considerations

1. **Password Encryption**: Store passwords encrypted in database
2. **OAuth2 Tokens**: Encrypt refresh and access tokens
3. **API Authentication**: All endpoints require admin authentication
4. **Input Validation**: Validate all SMTP configuration inputs
5. **Rate Limiting**: Prevent abuse of email sending functionality

---

## Future Enhancements

1. **Email Queue Monitoring**: View queued emails per transport group
2. **Bounce Handling**: Automatically detect and handle bounced emails
3. **Analytics**: Track delivery rates per account/group
4. **Webhook Notifications**: Notify on transport failures
5. **Template Inheritance**: Default transport group for all templates

---

## Files to Create/Modify

### Backend (Laravel)
```
backend/
├── database/migrations/
│   ├── 2026_XX_XXXXXX_create_mail_transport_accounts_table.php
│   ├── 2026_XX_XXXXXX_create_mail_transport_groups_table.php
│   └── 2026_XX_XXXXXX_create_mail_group_accounts_table.php
├── app/Models/
│   ├── MailTransportAccount.php
│   ├── MailTransportGroup.php
│   └── MailGroupAccount.php
├── app/Http/Controllers/Api/
│   ├── MailAccountController.php
│   └── MailGroupController.php
└── app/Services/
    └── MailTransportService.php (new)
```

### Frontend (Vue.js)
```
frontend/src/components/
├── AdminMailTransports.vue          (new - main page)
├── MailAccountForm.vue              (new)
├── MailGroupForm.vue                (new)
└── TransportTable.vue               (new - reusable)

frontend/src/router/index.js
  └── Add route for /admin/mail-transports

frontend/src/utils/adminApi.js
  └── Add API methods for mail accounts and groups
```

---

## Questions for User

1. **Transport Group Selection**: Should templates have a dropdown to select transport group, or should it be automatic based on template type?

2. **Rate Limiting Strategy**: 
   - Per-account rate limiting with round-robin distribution?
   - Global rate limiting across all accounts in a group?
   - Both options configurable per group?

3. **Failover Logic**:
   - Automatic on SMTP connection failure only?
   - Also include email delivery failures (bounce detection)?
   - Configurable per transport group?

4. **Modern Auth Support**:
   - OAuth2 support with refresh tokens for Exchange Online and Google Mail?
   - Or just better UI for existing authentication methods?

5. **Migration**: Should we automatically migrate existing mail settings to the new system, or require manual setup?
