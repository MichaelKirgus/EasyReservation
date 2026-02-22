# Archive Feature Implementation Plan

## Overview
This document outlines the implementation plan for adding an archive feature to store reservations and waitlist entries. The archive serves as a backup copy - original data remains in active tables.

---

## 1. Database Schema Changes

### New Table: `archives`
Stores archive metadata with unique name requirement.

```sql
CREATE TABLE archives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE COMMENT 'Unique name for the archive',
    description TEXT NULL COMMENT 'Optional description of what this archive contains',
    store_emails BOOLEAN DEFAULT 1 COMMENT 'Whether email addresses should be stored (can override per entry)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_name (name),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### New Table: `archive_reservations`
Stores archived reservation data with reference to original.

```sql
CREATE TABLE archive_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    archive_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference to the archive',
    original_reservation_id BIGINT UNSIGNED NOT NULL COMMENT 'Original reservation ID for reference',
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL COMMENT 'Encrypted if original was encrypted',
    payload JSON NULL,
    date_added TIMESTAMP NOT NULL,
    site_token VARCHAR(255) NULL,
    email_encrypted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
    INDEX idx_archive_id (archive_id),
    INDEX idx_original_reservation_id (original_reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### New Table: `archive_waitlist_entries`
Stores archived waitlist entry data with reference to original.

```sql
CREATE TABLE archive_waitlist_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    archive_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference to the archive',
    original_waitlist_entry_id BIGINT UNSIGNED NOT NULL COMMENT 'Original waitlist entry ID for reference',
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL COMMENT 'Encrypted if original was encrypted',
    payload JSON NULL,
    status VARCHAR(50) DEFAULT 'pending',
    reservation_id BIGINT UNSIGNED NULL COMMENT 'Reference to original reservation if promoted',
    promoted_at TIMESTAMP NULL,
    date_added TIMESTAMP NOT NULL,
    site_token VARCHAR(255) NULL,
    email_encrypted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
    INDEX idx_archive_id (archive_id),
    INDEX idx_original_waitlist_entry_id (original_waitlist_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 2. Application Settings

Add to `settings` table with default values:

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `archive_store_emails_by_default` | boolean | `1` | Whether email addresses should be stored in archives by default (can be overridden per archive) |
| `archive_moderator_access_enabled` | boolean | `0` | Whether moderators can access the archive (if false, only admins/superadmins can) |

---

## 3. Backend Implementation

### New Models

#### [`app/Models/Archive.php`](backend/app/Models/Archive.php)
```php
class Archive extends Model
{
    protected $fillable = ['name', 'description', 'store_emails'];
    
    public function reservations()
    {
        return $this->hasMany(ArchiveReservation::class, 'archive_id');
    }
    
    public function waitlistEntries()
    {
        return $this->hasMany(ArchiveWaitlistEntry::class, 'archive_id');
    }
}
```

#### [`app/Models/ArchiveReservation.php`](backend/app/Models/ArchiveReservation.php)
```php
class ArchiveReservation extends Model
{
    protected $fillable = [
        'archive_id', 'original_reservation_id', 'display_name',
        'email', 'payload', 'date_added', 'site_token', 'email_encrypted'
    ];
    
    protected $casts = [
        'payload' => 'array',
        'date_added' => 'datetime',
        'email_encrypted' => 'boolean',
    ];
    
    public function archive()
    {
        return $this->belongsTo(Archive::class);
    }
}
```

#### [`app/Models/ArchiveWaitlistEntry.php`](backend/app/Models/ArchiveWaitlistEntry.php)
```php
class ArchiveWaitlistEntry extends Model
{
    protected $fillable = [
        'archive_id', 'original_waitlist_entry_id', 'display_name',
        'email', 'payload', 'status', 'reservation_id', 'promoted_at',
        'date_added', 'site_token', 'email_encrypted'
    ];
    
    protected $casts = [
        'payload' => 'array',
        'date_added' => 'datetime',
        'promoted_at' => 'datetime',
        'email_encrypted' => 'boolean',
    ];
    
    public function archive()
    {
        return $this->belongsTo(Archive::class);
    }
}
```

### New Service

#### [`app/Services/ArchiveService.php`](backend/app/Services/ArchiveService.php)
```php
class ArchiveService
{
    // Create a new archive with current data
    public function createArchive(string $name, ?string $description = null, bool $storeEmails = true): Archive
    
    // Archive all current reservations and waitlist entries to an archive
    public function archiveData(Archive $archive, bool $storeEmails = null): void
    
    // Get archived reservations with optional filtering
    public function getReservations(Archive $archive, array $filters = []): Collection
    
    // Get archived waitlist entries with optional filtering
    public function getWaitlistEntries(Archive $archive, array $filters = []): Collection
    
    // Restore a single reservation from archive (creates new active entry)
    public function restoreReservation(ArchiveReservation $reservation): Reservation
    
    // Restore multiple reservations from archive
    public function restoreReservations(Collection $reservations): void
    
    // Delete an archive and all its data
    public function deleteArchive(Archive $archive): void
}
```

### New Controllers

#### [`app/Http/Controllers/Api/ArchiveController.php`](backend/app/Http/Controllers/Api/ArchiveController.php)
```php
class ArchiveController extends Controller
{
    // GET /api/admin/archives - List all archives (filtered by role permissions)
    public function index(): JsonResponse
    
    // POST /api/admin/archives - Create new archive
    public function store(Request $request): JsonResponse
    
    // DELETE /api/admin/archives/{archive} - Delete archive (admin/superadmin only)
    public function destroy(Archive $archive): JsonResponse
    
    // GET /api/admin/archives/{archive}/reservations - Get archived reservations
    public function getReservations(Archive $archive): JsonResponse
    
    // GET /api/admin/archives/{archive}/waitlist - Get archived waitlist entries
    public function getWaitlistEntries(Archive $archive): JsonResponse
    
    // POST /api/admin/archives/{archive}/restore-reservation/{reservationId} - Restore reservation
    public function restoreReservation(Archive $archive, ArchiveReservation $reservation): JsonResponse
    
    // GET /api/admin/archives/{archive}/download-csv - Download archive as CSV
    public function downloadCsv(Archive $archive, string $type = 'reservations'): StreamedResponse
}
```

### New Middleware

#### [`app/Http/Middleware/EnsureArchiveAccess.php`](backend/app/Http/Middleware/EnsureArchiveAccess.php)
```php
class EnsureArchiveAccess
{
    // Check if user has access to archive based on role and settings
    public function handle(Request $request, Closure $next): Response
}
```

---

## 4. API Routes

Add to [`routes/api.php`](backend/routes/api.php):

```php
use App\Http\Controllers\Api\ArchiveController;

// Admin routes (superadmin, admin)
Route::middleware(['role:superadmin,admin'])->group(function () {
    Route::get('/admin/archives', [ArchiveController::class, 'index']);
    Route::post('/admin/archives', [ArchiveController::class, 'store']);
    Route::delete('/admin/archives/{archive}', [ArchiveController::class, 'destroy']);
    
    // Archive-specific operations
    Route::get('/admin/archives/{archive}/reservations', [ArchiveController::class, 'getReservations']);
    Route::get('/admin/archives/{archive}/waitlist', [ArchiveController::class, 'getWaitlistEntries']);
    Route::post('/admin/archives/{archive}/restore-reservation/{reservation}', [ArchiveController::class, 'restoreReservation']);
    Route::get('/admin/archives/{archive}/download-csv/{type}', [ArchiveController::class, 'downloadCsv']);
});

// Moderator routes (if enabled via setting)
Route::middleware(['role:superadmin,admin,moderator'])->group(function () {
    Route::get('/moderator/archives', [ArchiveController::class, 'index']);
    Route::get('/moderator/archives/{archive}/reservations', [ArchiveController::class, 'getReservations']);
    Route::get('/moderator/archives/{archive}/waitlist', [ArchiveController::class, 'getWaitlistEntries']);
});
```

---

## 5. Frontend Implementation

### New Component: `AdminArchives.vue`

Location: [`frontend/src/components/AdminArchives.vue`](frontend/src/components/AdminArchives.vue)

Features:
- List of all archives with creation date
- Button to create new archive (shows dialog for name input)
- Archive details view showing reservations and waitlist entries
- Restore functionality for individual or multiple entries
- Delete archive button (visible only to admin/superadmin)
- Download CSV export

### Updated Component: `AdminSettings.vue`

Add new tab section for archive settings:

```javascript
const tabs = [
  // ... existing tabs
  { id: 'archive', labelKey: 'admin_settings_tab_archive', fallback: 'Archive' },
]

const tabFieldMap = {
  // ... existing mappings
  archive: new Set([
    'archive_store_emails_by_default',
    'archive_moderator_access_enabled',
  ]),
}
```

---

## 6. User Role Permissions

| Action | Superadmin | Admin | Moderator |
|--------|------------|-------|-----------|
| Create archive | ✅ | ✅ | ❌ |
| View archives list | ✅ | ✅ | ✅ (if enabled) |
| View archive details | ✅ | ✅ | ✅ (if enabled) |
| Restore from archive | ✅ | ✅ | ❌ |
| Delete archive | ✅ | ✅ | ❌ |

---

## 7. Migration Steps

1. **Create database migrations**
   - `create_archives_table.php`
   - `create_archive_reservations_table.php`
   - `create_archive_waitlist_entries_table.php`

2. **Add settings with defaults**
   ```php
   Setting::updateOrCreate(['name' => 'archive_store_emails_by_default'], ['value' => '1']);
   Setting::updateOrCreate(['name' => 'archive_moderator_access_enabled'], ['value' => '0']);
   ```

3. **Implement models** (Archive, ArchiveReservation, ArchiveWaitlistEntry)

4. **Implement service layer** (ArchiveService)

5. **Implement controller** (ArchiveController)

6. **Add middleware** (EnsureArchiveAccess if needed)

7. **Update routes** in api.php

8. **Create frontend components**
   - AdminArchives.vue
   - Update AdminSettings.vue

9. **Test implementation**
   - Create archive with name
   - Archive current data
   - Verify original data remains
   - Restore entries
   - Delete archive

---

## 8. Security Considerations

- Email addresses are stored encrypted in archives if they were encrypted in the original data
- Archive deletion is restricted to admin/superadmin only
- Moderator access to archives is configurable via `archive_moderator_access_enabled` setting
- Audit logging should record:
  - Archive creation (with name)
  - Data archiving events
  - Archive deletion

---

## 9. Translation Strings Needed

Add to both `resources/lang/en.json` and `resources/lang/de.json`:

```json
{
  "admin_archives_title": "Archives",
  "admin_archives_create_button": "Create Archive",
  "admin_archives_name_placeholder": "Archive name (e.g., 'January 2024')",
  "admin_archives_description_placeholder": "Optional description",
  "admin_archives_store_emails": "Store email addresses",
  "admin_archives_created_at": "Created at",
  "admin_archives_actions": "Actions",
  "admin_archives_view_reservations": "View Reservations",
  "admin_archives_view_waitlist": "View Waitlist",
  "admin_archives_restore": "Restore",
  "admin_archives_delete": "Delete",
  "admin_archives_confirm_delete": "Are you sure you want to delete this archive? All archived data will be permanently removed.",
  "admin_archives_archive_success": "Archive created successfully",
  "admin_archives_no_archives": "No archives found",
  "admin_archives_column_original_id": "Original ID",
  "admin_archives_restore_success": "Entry restored successfully"
}
```

---

## Implementation Order

1. Database migrations
2. Models and relationships
3. ArchiveService with core logic
4. ArchiveController with API endpoints
5. Frontend components (AdminArchives.vue)
6. Settings integration (AdminSettings.vue)
7. Testing and documentation
