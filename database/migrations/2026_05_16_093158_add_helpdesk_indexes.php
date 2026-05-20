<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['customer_id', 'status'], 'idx_tickets_customer_status');
            $table->index(['division_id', 'status'], 'idx_tickets_division_status');
            $table->index(['assigned_to', 'status'], 'idx_tickets_assigned_status');
        });

        DB::statement("CREATE INDEX idx_tickets_sla_fr_deadline ON tickets(sla_fr_deadline_at) WHERE sla_fr_status = 'running'");
        DB::statement("CREATE INDEX idx_tickets_sla_res_deadline ON tickets(sla_resolution_deadline_at) WHERE sla_resolution_status = 'running'");

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['ticket_id', 'created_at'], 'idx_messages_ticket');
            $table->index(['customer_id', 'created_at'], 'idx_messages_customer');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['phone_number'], 'idx_customers_phone');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'idx_audit_logs_user');
        });

        Schema::table('public_holidays', function (Blueprint $table) {
            $table->index(['date'], 'idx_public_holidays_date');
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->index(['is_active'], 'idx_divisions_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_customer_status');
            $table->dropIndex('idx_tickets_division_status');
            $table->dropIndex('idx_tickets_assigned_status');
        });

        DB::statement('DROP INDEX IF EXISTS idx_tickets_sla_fr_deadline');
        DB::statement('DROP INDEX IF EXISTS idx_tickets_sla_res_deadline');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_ticket');
            $table->dropIndex('idx_messages_customer');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('idx_customers_phone');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_logs_user');
        });

        Schema::table('public_holidays', function (Blueprint $table) {
            $table->dropIndex('idx_public_holidays_date');
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->dropIndex('idx_divisions_active');
        });
    }
};
