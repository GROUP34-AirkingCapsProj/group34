<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_transactions', function (Blueprint $table) {
            $table->bigIncrements('txn_id');
            $table->unsignedBigInteger('budget_id');
            $table->string('txn_type', 20); // commit | release | spend | adjustment
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('pr_id')->nullable();
            $table->unsignedBigInteger('po_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->nullable(); // immutable rows — no updated_at

            $table->foreign('budget_id')->references('budget_id')->on('budget_allocations')->cascadeOnDelete();
            $table->foreign('pr_id')->references('pr_id')->on('purchase_requests')->nullOnDelete();
            $table->foreign('po_id')->references('po_id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('performed_by')->references('user_id')->on('users')->nullOnDelete();
            $table->index(['budget_id', 'txn_type']);
            $table->index(['pr_id', 'txn_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_transactions');
    }
};
