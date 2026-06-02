<?php

namespace Database\Seeders;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Workflow\Models\WorkflowTemplate;
use Illuminate\Database\Seeder;

class DefaultWorkflowTemplateSeeder extends Seeder
{
    public function run(): void
    {
        Organization::all()->each(function (Organization $org): void {
            WorkflowTemplate::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'is_default'      => true,
                ],
                [
                    'name'        => 'Standard Legal Review',
                    'description' => 'Two-stage review: manager reviews, then admin approves.',
                    'stages'      => [
                        ['name' => 'Manager Review',  'approver_role' => 'manager'],
                        ['name' => 'Admin Approval',  'approver_role' => 'admin'],
                    ],
                ]
            );
        });
    }
}
