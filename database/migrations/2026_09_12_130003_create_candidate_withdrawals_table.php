<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->string('stage'); // label like "Before work permit issued — 20% deduction"
            $table->decimal('deduction_percentage', 5, 2)->default(0);
            $table->decimal('total_paid_so_far', 12, 2)->default(0);
            $table->decimal('cancellation_fee', 12, 2)->default(0);
            $table->decimal('refund_payable', 12, 2)->default(0);
            $table->text('reason')->nullable();
            $table->enum('payment_mode', ['cheque', 'bank_transfer', 'cash'])->default('cheque');
            $table->string('cheque_number')->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('alert_finance_before_days')->nullable();
            $table->enum('status', ['pending_hr_approval', 'cheque_issued', 'cleared'])->default('pending_hr_approval');
            $table->timestamp('withdrawn_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_withdrawals');
    }
};
