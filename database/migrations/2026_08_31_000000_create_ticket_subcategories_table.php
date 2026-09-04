<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_subcategories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('division_id')->references('id')->on('divisions')->cascadeOnDelete();
            $table->unique(['division_id', 'name']);
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->uuid('global_subcategory_id')->nullable();
            $table->uuid('division_subcategory_id')->nullable();

            $table->foreign('global_subcategory_id')->references('id')->on('ticket_subcategories')->nullOnDelete();
            $table->foreign('division_subcategory_id')->references('id')->on('ticket_subcategories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['global_subcategory_id']);
            $table->dropForeign(['division_subcategory_id']);
            $table->dropColumn(['global_subcategory_id', 'division_subcategory_id']);
        });

        Schema::dropIfExists('ticket_subcategories');
    }
};
