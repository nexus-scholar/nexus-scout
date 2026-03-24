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
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->index();
            $table->integer('year')->nullable()->index();
            $table->text('abstract')->nullable();
            $table->string('venue')->nullable();
            $table->string('url')->nullable();
            $table->string('language')->default('en')->index();
            $table->integer('cited_by_count')->default(0);

            $table->string('doi')->unique()->index()->nullable();
            $table->string('arxiv_id')->index()->nullable();
            $table->string('pubmed_id')->index()->nullable();
            $table->string('openalex_id')->index()->nullable();
            $table->string('s2_id')->index()->nullable();

            $table->json('raw_data')->nullable();

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
