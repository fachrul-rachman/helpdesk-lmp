<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('division_user', function (Blueprint $table): void {
            $table->uuid('division_id');
            $table->uuid('user_id');
            $table->primary(['division_id', 'user_id']);
            $table->foreign('division_id')->references('id')->on('divisions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::table('division_user')->insertUsing(
            ['user_id', 'division_id'],
            DB::table('users')->select(['id', 'division_id'])->whereNotNull('division_id'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('division_user');
    }
};
