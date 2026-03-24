<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('template_type')->index();

            // Core Identity
            $table->text('objective');
            $table->string('status')->default('clarification_pending');

            // Interview Phase
            $table->text('theme_context')->nullable();

            // Pipeline State & Final Artifacts
            $table->json('state_data')->nullable();
            $table->json('protocol')->nullable();
            $table->text('export_yaml')->nullable();

            // Agent Auditing
            $table->json('agent_interactions')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
