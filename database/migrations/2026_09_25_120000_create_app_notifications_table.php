<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notification inbox (named to avoid Laravel's own `notifications` table)
        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->string('link')->nullable();
            // Prevents duplicate reminders (e.g. one expiry alert per document per day)
            $table->string('dedupe_key')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['user_id', 'dedupe_key']);
        });

        // Browser / mobile push tokens (Firebase Cloud Messaging)
        Schema::create('fcm_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 10)->default('WEB');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
        Schema::dropIfExists('app_notifications');
    }
};
