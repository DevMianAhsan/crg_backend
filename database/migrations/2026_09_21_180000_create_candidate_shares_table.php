<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_shares', function (Blueprint $table): void {
            $table->id();
            $table->string('share_token')->unique();
            $table->string('title')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('company_name')->nullable();
            $table->json('candidate_ids');
            $table->string('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_shares');
    }
};
