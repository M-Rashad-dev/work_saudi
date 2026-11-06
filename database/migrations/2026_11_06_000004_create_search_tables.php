<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query');
            $table->string('group', 20);          // discovery | hiring
            $table->string('language', 5)->default('ar');
            $table->string('city', 50)->nullable(); // key from config('outreach.cities'); null = nationwide
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamps();

            $table->unique(['query', 'language']);
            $table->index(['is_active', 'last_run_at']);
        });

        Schema::create('search_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['type', 'started_at']);
        });

        Schema::create('blocked_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('category', 30)->nullable(); // directory | job_board | social | news | gov | reference | other
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('blocked_domains');
        Schema::dropIfExists('search_runs');
        Schema::dropIfExists('search_queries');
    }
};
