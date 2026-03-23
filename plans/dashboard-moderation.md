# Dashboard Moderation Feature - Technical Plan

## Overview
Create a new "Dashboard" page under the "Moderation" navigation section that displays statistics and allows quick action execution.

## Requirements Summary
- New tab in moderation menu (first entry)
- Statistics dashboard with charts:
  - Pie chart: Reservation vs Waitlist counts
  - Line/Bar chart: Daily reservation trends (X: days, Y: count)
  - Statistics cards: Mail validation pending, Rate limit entries
- Combobox for selecting action lists (only those with `moderation_selectable=true`)
- Execute button for selected action list

## Implementation Steps

### Backend Changes

#### 1. ActionList Model Update
**File:** [`backend/app/Models/ActionList.php`](backend/app/Models/ActionList.php)

Add new field to database and model:
```php
protected $fillable = [
    'name',
    'description',
    'active',
    'moderation_selectable',  // NEW
];
```

Create migration: `create_moderation_selectable_column_on_action_lists_table`

#### 2. ActionListController Update
**File:** [`backend/app/Http/Controllers/Api/ActionListController.php`](backend/app/Http/Controllers/Api/ActionListController.php)

Update `index()` and `show()` methods to include `moderation_selectable` in responses.

#### 3. New Dashboard Statistics API Endpoint
**File:** [`backend/app/Http/Controllers/Api/DiagnosticsController.php`](backend/app/Http/Controllers/Api/DiagnosticsController.php) (or new ModerationDashboardController)

Add endpoint: `/api/admin/moderation-dashboard/stats`

Returns:
```json
{
  "reservation_count": 123,
  "waitlist_count": 45,
  "mail_validation_pending": 67,
  "rate_limit_entries": 89,
  "daily_trends": [
    { "date": "2026-03-17", "count": 10 },
    { "date": "2026-03-18", "count": 15 },
    ... (last 7 days)
  ]
}
```

#### 4. ActionList Execution API Endpoint
**File:** [`backend/app/Http/Controllers/Api/ActionListController.php`](backend/app/Http/Controllers/Api/ActionListController.php)

Add endpoint: `/api/admin/moderation-dashboard/action-list/{id}/execute`

This endpoint should:
- Only execute action lists where `moderation_selectable = true`
- Return success/error response

### Frontend Changes

#### 1. New Dashboard Component
**File:** [`frontend/src/components/ModerationDashboard.vue`](frontend/src/components/ModerationDashboard.vue)

Structure:
```vue
<template>
  <div>
    <!-- Statistics Cards -->
    <div class="stats-cards">
      <div class="stat-card">Reservation Count</div>
      <div class="stat-card">Waitlist Count</div>
      <div class="stat-card">Mail Validation Pending</div>
      <div class="stat-card">Rate Limit Entries</div>
    </div>

    <!-- Charts -->
    <div class="charts-container">
      <!-- Pie Chart: Reservation vs Waitlist -->
      <canvas id="reservation-waitlist-pie"></canvas>
      
      <!-- Line/Bar Chart: Daily Trends -->
      <canvas id="daily-trends-chart"></canvas>
    </div>

    <!-- Action List Selector -->
    <div class="action-list-selector">
      <select v-model="selectedActionListId">
        <option v-for="list in actionLists" :value="list.id">{{ list.name }}</option>
      </select>
      <button @click="executeSelected">Execute</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Chart, PieController, BarController, CategoryScale, LinearScale, Tooltip, Legend, ArcElement, LineElement, PointElement } from 'chart.js'

Chart.register(...)

const stats = ref(null)
const actionLists = ref([])
const selectedActionListId = ref(null)

// Load statistics and action lists on mount
onMounted(() => {
  loadStats()
  loadActionLists()
})
</script>
```

#### 2. Navigation Update
**File:** [`frontend/src/App.vue`](frontend/src/App.vue:134)

Add new tab as first entry in moderation group:
```javascript
tabs: [
  { to: '/moderation/dashboard', label: tr('nav_tab_dashboard', 'Dashboard'), show: !!(currentUser && currentUser.role) },  // NEW - FIRST
  { to: '/moderation/reservations', label: tr('nav_tab_reservations', 'Reservations'), show: !!(currentUser && currentUser.role) },
  ...
]
```

#### 3. ActionListDialog Update
**File:** [`frontend/src/components/ActionListDialog.vue`](frontend/src/components/ActionListDialog.vue)

Add checkbox for `moderation_selectable` setting.

## Chart.js Configuration

### Pie Chart (Reservation vs Waitlist)
```javascript
new Chart(ctx, {
  type: 'pie',
  data: {
    labels: ['Reservations', 'Waitlist'],
    datasets: [{
      data: [reservationCount, waitlistCount],
      backgroundColor: ['#4ade80', '#f87171']
    }]
  },
  options: { responsive: true }
})
```

### Line/Bar Chart (Daily Trends)
```javascript
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: dailyTrends.map(t => t.date),
    datasets: [{
      label: 'Reservations per Day',
      data: dailyTrends.map(t => t.count),
      backgroundColor: '#4ade80'
    }]
  },
  options: { responsive: true }
})
```

## Database Migration

```php
Schema::table('action_lists', function (Blueprint $table) {
    $table->boolean('moderation_selectable')->default(true);
});
```

Default value: `true` (all existing action lists will be visible in moderation by default)

## Translation Keys Needed

- `nav_tab_dashboard`: "Dashboard"
- `dashboard_stat_reservation_count`: "Reservation Count"
- `dashboard_stat_waitlist_count`: "Waitlist Count"
- `dashboard_stat_mail_validation_pending`: "Mail Validation Pending"
- `dashboard_stat_rate_limit_entries`: "Rate Limit Entries"
- `dashboard_chart_reservation_vs_waitlist`: "Reservations vs Waitlist"
- `dashboard_chart_daily_trends`: "Daily Reservation Trends"
- `dashboard_action_list_select`: "Select Action List"
- `dashboard_action_list_execute`: "Execute"

## File Structure Summary

| Type | File | Description |
|------|------|-------------|
| Backend Model | `app/Models/ActionList.php` | Add moderation_selectable field |
| Backend Migration | `database/migrations/...` | Add column to action_lists table |
| Backend Controller | `app/Http/Controllers/Api/DiagnosticsController.php` | New stats endpoint |
| Backend Controller | `app/Http/Controllers/Api/ActionListController.php` | Execute endpoint + moderation_selectable in responses |
| Frontend Component | `src/components/ModerationDashboard.vue` | Dashboard UI with charts |
| Frontend Router | `src/App.vue` | Add navigation tab |
| Frontend Dialog | `src/components/ActionListDialog.vue` | Add moderation_selectable checkbox |

## Implementation Order

1. Create database migration
2. Update ActionList model and controller
3. Create backend stats endpoint
4. Create backend execute endpoint
5. Create frontend Dashboard component
6. Update navigation in App.vue
7. Update ActionListDialog
8. Add translation keys
