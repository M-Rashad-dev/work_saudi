<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);           // phone | email
            $table->string('value', 191);         // E.164 for phones, lowercase for emails
            $table->string('label', 20)->default('general');
            $table->boolean('is_mobile')->default(false);
            $table->boolean('is_whatsapp')->default(false);
            $table->string('source_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
