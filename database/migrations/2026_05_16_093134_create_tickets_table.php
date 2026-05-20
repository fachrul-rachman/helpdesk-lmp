<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('division_id');
            $table->uuid('assigned_to')->nullable();
            $table->enum('created_by', ['ai', 'spv']);
            $table->enum('priority', ['low', 'medium', 'high']);
            $table->enum('status', ['new', 'open', 'pending', 'on_progress', 'queue', 'solved', 'closed']);
            $table->string('subject', 500);
            $table->text('notes')->nullable();
            $table->decimal('ai_confidence', 5, 2)->nullable();

            $table->timestamp('sla_fr_started_at')->nullable();
            $table->timestamp('sla_fr_deadline_at')->nullable();
            $table->timestamp('sla_fr_completed_at')->nullable();
            $table->enum('sla_fr_status', ['running', 'done', 'overdue'])->default('running');

            $table->timestamp('sla_resolution_started_at')->nullable();
            $table->timestamp('sla_resolution_deadline_at')->nullable();
            $table->timestamp('sla_resolution_paused_at')->nullable();
            $table->integer('sla_resolution_paused_duration')->default(0);
            $table->enum('sla_resolution_status', ['waiting', 'running', 'paused', 'done', 'overdue'])->default('waiting');

            $table->integer('queue_position')->nullable();
            $table->integer('queue_priority')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('solved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('division_id')->references('id')->on('divisions')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
