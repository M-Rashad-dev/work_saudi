<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('domain')->nullable()->unique();
            $table->string('website', 2048)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('linkedin_url', 2048)->nullable();
            $table->string('google_maps_url', 2048)->nullable();
            $table->string('job_post_url', 2048)->nullable();
            $table->string('source', 20)->default('manual');
            $table->text('source_snippet')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('reason_ar')->nullable();
            $table->json('services')->nullable();
            $table->json('tech_hints')->nullable();
            $table->boolean('is_software_company')->default(false);
            $table->boolean('is_hiring_devs')->default(false);
            $table->unsignedTinyInteger('relevance')->default(0);
            $table->string('llm_driver', 20)->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamp('enriched_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['normalized_name', 'city']);
            $table->index(['status', 'relevance']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
