<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('document_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('workflow_templates')->nullOnDelete();
            $table->foreignUuid('started_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('in_review');
            $table->unsignedTinyInteger('current_stage_index')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'status']);
            $table->index('organization_id');
        });
    }
    public function down(): void { Schema::dropIfExists('document_reviews'); }
};
