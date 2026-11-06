<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_batches', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_batch_id')->constrained()->cascadeOnDelete();
            // unique: a company can appear in exactly one batch, ever.
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('action', 20)->default('pending');
            $table->timestamp('action_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['daily_batch_id', 'position']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_batch_items');
        Schema::dropIfExists('daily_batches');
    }
};
