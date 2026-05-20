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
        Schema::create('divisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255)->unique();
            $table->text('description');
            $table->text('handles');
            $table->text('not_handles');
            $table->text('ticket_examples');
            $table->integer('sla_resolution_value');
            $table->enum('sla_resolution_unit', ['hours', 'days']);
            $table->integer('sla_resolution_reminder_value');
            $table->enum('sla_resolution_reminder_unit', ['hours', 'days']);
            $table->boolean('is_fallback')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('divisions');
    }
};
