# Encrypted Settings Documentation

## Overview

This document describes how sensitive settings (like passwords and API keys) are automatically encrypted in the database using Laravel's built-in encryption capabilities.

## Architecture

### Components

| Component | File | Purpose |
|-----------|------|---------|
| Configuration | [`config/encrypted-settings.php`](backend/config/encrypted-settings.php) | Defines which fields should be encrypted |
| Migration | [`database/migrations/2026_02_15_000000_add_is_encrypted_to_settings_table.php`](backend/database/migrations/2026_02_15_000000_add_is_encrypted_to_settings_table.php) | Adds `is_encrypted` column to settings table |
| Model | [`app/Models/Setting.php`](backend/app/Models/Setting.php) | Handles automatic encryption/decryption via accessors/mutators |
| Service | [`app/Services/SettingsService.php`](backend/app/Services/SettingsService.php) | Retrieves settings (transparently decrypts values) |

### How It Works

```
┌─────────────────┐
│  SettingsController │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│   Setting Model │
│  (Mutator)      │  ──► Encrypts value if field is in encrypted list
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│   Database      │  ──► Stores encrypted value + is_encrypted flag
└─────────────────┘

┌─────────────────┐
│   Setting Model │
│  (Accessor)     │  ──► Decrypts value if is_encrypted = true
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ SettingsService │  ──► Returns decrypted value to application
└─────────────────┘
```

## Configuration

### Adding New Encrypted Fields

Edit [`config/encrypted-settings.php`](backend/config/encrypted-settings.php):

```php
return [
    'fields' => [
        'mail_password',
        'mail_username',
        // Add new fields here
        'api_secret_key',
        'stripe_api_key',
    ],
];
```

Any setting name in this list will be:
1. Automatically encrypted when saved to the database
2. Automatically decrypted when retrieved via the model

## Usage Examples

### Setting a Value (Automatic Encryption)

```php
// The value will be automatically encrypted if 'mail_password' is in the config
Setting::query()->updateOrCreate(
    ['name' => 'mail_password'],
    ['value' => 'my-smtp-password']
);
```

### Getting a Value (Automatic Decryption)

```php
// The value will be automatically decrypted if stored as encrypted
$password = Setting::where('name', 'mail_password')->first()->value;
// or via SettingsService
$password = app(SettingsService::class)->get('mail_password');
```

## Database Schema

The `settings` table has been extended with an `is_encrypted` column:

```sql
CREATE TABLE settings (
    name VARCHAR(255) PRIMARY KEY,
    value VARCHAR(2048),
    is_encrypted BOOLEAN DEFAULT FALSE
);
```

### Value Storage Examples

| Setting Name | Plain Text Value | Stored Value |
|--------------|------------------|--------------|
| `reservation_name` | "Event Reservation" | `"Event Reservation"` (plain) |
| `mail_password` | "secret123" | `"eyJpdiI6IlhYWFhYIiwidmFsdWUiOiJZWVlZWSJ9..."` (encrypted) |

## Security Considerations

### What Gets Encrypted
- **SMTP passwords** (`mail_password`)
- **SMTP usernames** (`mail_username`)
- Any field added to `config/encrypted-settings.php`

### Encryption Details
- **Algorithm**: OpenSSL with AES-256-CBC
- **Key Source**: `APP_KEY` from `.env`
- **Reversible**: Yes (can be decrypted with the app key)

### Important Notes

1. **APP_KEY Security**: The encryption is only as secure as your `APP_KEY`. Never expose this value.

2. **Database Access**: Users with direct database access can still see encrypted values. The encryption protects against:
   - Accidental exposure in backups
   - Database dumps shared incorrectly
   - Admin panel data display (values shown as encrypted)

3. **Application Access**: The application can decrypt all encrypted values using the app key. This is necessary for SMTP authentication.

4. **Migration Required**: Run `php artisan migrate` after deployment to add the `is_encrypted` column.

## Troubleshooting

### Values Not Decrypting
- Check that `is_encrypted = 1` in the database
- Verify `APP_KEY` is set correctly in `.env`
- Ensure the field name matches exactly (case-sensitive) in `config/encrypted-settings.php`

### Decryption Errors
If you see `Illuminate\Contracts\Encryption\DecryptException`:
- The data may have been encrypted with a different APP_KEY
- Check for data corruption in the database

## Future Enhancements

Potential improvements to consider:

1. **Audit Logging**: Log when encrypted settings are accessed
2. **Key Rotation**: Implement periodic key rotation with re-encryption
3. **Environment-based Encryption**: Only encrypt in production
4. **Field-level Access Control**: Restrict which roles can view/edit encrypted fields
