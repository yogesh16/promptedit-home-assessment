<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('prompt', 4000);
            $table->string('size', 16);
            $table->string('quality', 8);
            $table->json('reference_images')->nullable();
            $table->string('status', 16)->default('queued');
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_generation_id')->nullable()->unique();
            $table->json('rates')->nullable();
            $table->decimal('estimated_cost_min', 18, 9)->nullable();
            $table->decimal('estimated_cost_max', 18, 9)->nullable();
            $table->json('usage')->nullable();
            $table->decimal('provider_cost', 18, 9)->nullable();
            $table->decimal('customer_charge', 18, 9)->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
