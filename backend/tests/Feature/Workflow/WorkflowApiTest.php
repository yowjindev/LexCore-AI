<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Modules\Compliance\Models\ComplianceFlag;
use App\Modules\Documents\Models\Document;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Workflow\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('s3');
        Queue::fake();
    }

    public function test_admin_can_start_review(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin');
        $doc  = Document::factory()->create(['organization_id' => $org->id, 'status' => Document::STATUS_ANALYZED]);

        $this->actingAs($user)
            ->postJson("/api/v1/documents/{$doc->id}/review")
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'in_review');
    }

    public function test_staff_cannot_start_review(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('staff');
        $doc  = Document::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->postJson("/api/v1/documents/{$doc->id}/review")
            ->assertStatus(403);
    }

    public function test_get_review_returns_null_when_none_exists(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('staff');
        $doc  = Document::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->getJson("/api/v1/documents/{$doc->id}/review")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_task_index_scoped_to_organization(): void
    {
        $org1  = Organization::factory()->create();
        $org2  = Organization::factory()->create();
        $user1 = User::factory()->create(['organization_id' => $org1->id]);
        $user2 = User::factory()->create(['organization_id' => $org2->id]);
        $user1->assignRole('staff');

        $flag = ComplianceFlag::factory()->create(['organization_id' => $org1->id]);
        Task::create([
            'organization_id' => $org1->id,
            'assignable_type' => 'compliance_flag',
            'assignable_id'   => $flag->id,
            'created_by'      => $user1->id,
            'title'           => 'Task for org1',
            'status'          => Task::STATUS_OPEN,
            'priority'        => Task::PRIORITY_HIGH,
        ]);

        $flag2 = ComplianceFlag::factory()->create(['organization_id' => $org2->id]);
        Task::create([
            'organization_id' => $org2->id,
            'assignable_type' => 'compliance_flag',
            'assignable_id'   => $flag2->id,
            'created_by'      => $user2->id,
            'title'           => 'Task for org2',
            'status'          => Task::STATUS_OPEN,
            'priority'        => Task::PRIORITY_HIGH,
        ]);

        $this->actingAs($user1)
            ->getJson('/api/v1/tasks')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Task for org1');
    }

    public function test_task_can_be_completed(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin');

        $flag = ComplianceFlag::factory()->create(['organization_id' => $org->id]);
        $task = Task::create([
            'organization_id' => $org->id,
            'assignable_type' => 'compliance_flag',
            'assignable_id'   => $flag->id,
            'created_by'      => $user->id,
            'title'           => 'Test task',
            'status'          => Task::STATUS_OPEN,
            'priority'        => Task::PRIORITY_MEDIUM,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/v1/tasks/{$task->id}", ['action' => 'complete'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Task::STATUS_COMPLETED);
    }

    public function test_task_can_be_assigned_to_org_member(): void
    {
        $org      = Organization::factory()->create();
        $manager  = User::factory()->create(['organization_id' => $org->id]);
        $manager->assignRole('manager');
        $assignee = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jane Approver']);
        $assignee->assignRole('staff');

        $flag = ComplianceFlag::factory()->create(['organization_id' => $org->id]);
        $task = Task::create([
            'organization_id' => $org->id,
            'assignable_type' => 'compliance_flag',
            'assignable_id'   => $flag->id,
            'created_by'      => $manager->id,
            'title'           => 'Needs an owner',
            'status'          => Task::STATUS_OPEN,
            'priority'        => Task::PRIORITY_HIGH,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/tasks/{$task->id}", ['action' => 'assign', 'assigned_to' => $assignee->id])
            ->assertStatus(200)
            ->assertJsonPath('data.assigned_to', $assignee->id)
            ->assertJsonPath('data.status', Task::STATUS_IN_PROGRESS);
    }

    public function test_cannot_assign_task_to_user_outside_organization(): void
    {
        $org1     = Organization::factory()->create();
        $org2     = Organization::factory()->create();
        $manager  = User::factory()->create(['organization_id' => $org1->id]);
        $manager->assignRole('manager');
        $outsider = User::factory()->create(['organization_id' => $org2->id]);

        $flag = ComplianceFlag::factory()->create(['organization_id' => $org1->id]);
        $task = Task::create([
            'organization_id' => $org1->id,
            'assignable_type' => 'compliance_flag',
            'assignable_id'   => $flag->id,
            'created_by'      => $manager->id,
            'title'           => 'Needs an owner',
            'status'          => Task::STATUS_OPEN,
            'priority'        => Task::PRIORITY_HIGH,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/tasks/{$task->id}", ['action' => 'assign', 'assigned_to' => $outsider->id])
            ->assertStatus(404);
    }

    public function test_task_index_includes_assignee_name(): void
    {
        $org      = Organization::factory()->create();
        $user     = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('staff');
        $assignee = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jane Approver']);

        $flag = ComplianceFlag::factory()->create(['organization_id' => $org->id]);
        $task = Task::create([
            'organization_id' => $org->id,
            'assignable_type' => 'compliance_flag',
            'assignable_id'   => $flag->id,
            'assigned_to'     => $assignee->id,
            'created_by'      => $user->id,
            'title'           => 'Assigned task',
            'status'          => Task::STATUS_IN_PROGRESS,
            'priority'        => Task::PRIORITY_MEDIUM,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/tasks')
            ->assertStatus(200)
            ->assertJsonPath('data.0.assigned_to', $assignee->id)
            ->assertJsonPath('data.0.assignee.name', 'Jane Approver');
    }

    public function test_unauthenticated_cannot_access_workflow(): void
    {
        $this->getJson('/api/v1/tasks')->assertStatus(401);
    }
}
