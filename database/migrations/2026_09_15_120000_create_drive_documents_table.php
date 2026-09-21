<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type')->default('application/octet-stream');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_documents');
    }
};
