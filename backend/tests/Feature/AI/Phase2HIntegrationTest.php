<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\Contracts\EmbeddingClientContract;
use App\Modules\AI\DTOs\AIResponse;
use App\Modules\AI\Models\AiRequest;
use App\Modules\Documents\Models\Document;
use App\Modules\Organizations\Models\Organization;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase2HIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('s3');
        Queue::fake();
    }

    public function test_ai_requests_table_has_new_columns(): void
    {
        $this->assertTrue(\Schema::hasColumn('ai_requests', 'user_id'));
        $this->assertTrue(\Schema::hasColumn('ai_requests', 'raw_response'));
    }

    public function test_chat_creates_ai_request_with_user_id_and_raw_response(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin');
        $doc  = Document::factory()->create([
            'organization_id' => $org->id,
            'status'          => Document::STATUS_ANALYZED,
        ]);

        $this->mock(AIClientContract::class, fn ($m) =>
            $m->shouldReceive('complete')->andReturn(
                new AIResponse('The answer is yes.', 100, 50, 'gemini-test')
            )
        );
        $this->mock(EmbeddingClientContract::class, fn ($m) =>
            $m->shouldReceive('embed')->andReturn(array_fill(0, 3072, 0.1))
        );

        $this->actingAs($user)
            ->postJson("/api/v1/documents/{$doc->id}/conversations", [
                'message' => 'What is the rental amount?',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('ai_requests', [
            'job_type' => 'chat',
            'user_id'  => $user->id,
            'status'   => 'success',
        ]);

        $req = AiRequest::where('job_type', 'chat')->first();
        $this->assertNotNull($req->raw_response);
        $this->assertSame('The answer is yes.', $req->raw_response);
    }
}
