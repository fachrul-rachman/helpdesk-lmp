<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_takeover_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('requested_by');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'closed'])->default('pending');
            $table->uuid('approved_by')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['ticket_id', 'status'], 'idx_takeover_ticket_status');
            $table->index(['requested_by', 'status'], 'idx_takeover_requested_by_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_takeover_requests');
    }
};

