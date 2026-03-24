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
        Schema::create('search_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('search_run_id')->constrained('search_runs')->cascadeOnDelete();
            $table->foreignUuid('query_id')->constrained('query_records')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('provider_doc_id');
            $table->integer('rank')->nullable();
            $table->timestamp('retrieved_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_results');
    }
};
