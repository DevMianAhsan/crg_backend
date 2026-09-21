<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_no')->unique();
            $table->date('date');
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('type'); // placement_fee, processing_fee, deposit, refund, payment, cancellation_fee
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('PKR');
            $table->string('status', 20)->default('paid'); // paid, pending, overdue, cancelled
            $table->text('description')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('cheque_number')->nullable();
            $table->date('cheque_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
