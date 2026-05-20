<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('meta_template_id', 50)->unique();
            $table->string('name', 255);
            $table->string('language', 20)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('category', 50)->nullable();
            $table->string('sub_category', 50)->nullable();
            $table->json('components')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_whatsapp_templates');
    }
};

