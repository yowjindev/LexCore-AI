<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('stages');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'is_default']);
        });
    }
    public function down(): void { Schema::dropIfExists('workflow_templates'); }
};
