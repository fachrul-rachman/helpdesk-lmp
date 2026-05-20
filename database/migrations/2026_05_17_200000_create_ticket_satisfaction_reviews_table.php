<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_satisfaction_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('ticket_id');
            $table->uuid('customer_id');
            $table->unsignedTinyInteger('rating'); // 1-5
            $table->text('feedback')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique('ticket_id', 'uq_reviews_ticket');
            $table->index('customer_id', 'idx_reviews_customer');

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_satisfaction_reviews');
    }
};

