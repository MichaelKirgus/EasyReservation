<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_audit_log_count(): void
    {
        $admin = $this->createApiUser('admin');
        AuditLog::create([
            'route' => '/api/example',
            'method' => 'GET',
            'payload' => json_encode(['ok' => true]),
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/audit-log/count');

        $response->assertOk()->assertJson(['count' => 1]);
    }

    public function test_superadmin_can_list_and_clear_audit_logs(): void
    {
        $superadmin = $this->createApiUser('superadmin');
        AuditLog::create([
            'route' => '/api/example',
            'method' => 'POST',
            'payload' => json_encode(['name' => 'value']),
        ]);

        $list = $this->withHeaders($this->apiHeaders($superadmin))
            ->getJson('/api/audit-logs?route=example');
        $list->assertOk()->assertJsonCount(1)->assertJsonPath('0.route', '/api/example');

        $clear = $this->withHeaders($this->apiHeaders($superadmin))
            ->postJson('/api/audit-logs/clear');
        $clear->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['route' => '/api/example']);
    }

    public function test_admin_can_read_worker_stats_shape(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/worker-stats');

        $response->assertOk()
            ->assertJsonStructure(['driver', 'ttl', 'workers']);
    }

    public function test_moderator_cannot_read_superadmin_audit_logs(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/audit-logs');

        $response->assertForbidden();
    }
}
