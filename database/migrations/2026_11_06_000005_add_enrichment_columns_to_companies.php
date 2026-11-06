<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Cleaned text from the crawled pages — input for the AI step (Phase 5).
            $table->mediumText('website_text')->nullable()->after('source_snippet');
            $table->text('meta_description')->nullable()->after('website_text');
            $table->json('enrichment')->nullable()->after('meta_description'); // pages fetched, errors, renderer used
            $table->unsignedTinyInteger('analysis_attempts')->default(0)->after('llm_driver');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['website_text', 'meta_description', 'enrichment', 'analysis_attempts']);
        });
    }
};
