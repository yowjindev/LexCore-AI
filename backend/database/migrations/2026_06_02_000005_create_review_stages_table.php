<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('review_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained('document_reviews')->cascadeOnDelete();
            $table->unsignedTinyInteger('stage_index');
            $table->string('stage_name', 100);
            $table->string('approver_role', 50);
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20)->default('pending');
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'stage_index']);
            $table->index(['review_id', 'decision']);
        });
    }
    public function down(): void { Schema::dropIfExists('review_stages'); }
};
