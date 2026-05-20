<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS tickets_ticket_seq_seq START 1;');
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->bigInteger('ticket_seq')->nullable()->after('id');
            $table->string('ticket_number', 20)->nullable()->after('ticket_seq');
        });

        if ($driver === 'pgsql') {
            DB::statement("
                WITH ordered AS (
                    SELECT id, row_number() OVER (ORDER BY created_at, id) AS rn
                    FROM tickets
                )
                UPDATE tickets t
                SET ticket_seq = ordered.rn
                FROM ordered
                WHERE t.id = ordered.id
            ");

            DB::statement("SELECT setval('tickets_ticket_seq_seq', (SELECT COALESCE(MAX(ticket_seq), 0) FROM tickets))");
            DB::statement("ALTER TABLE tickets ALTER COLUMN ticket_seq SET DEFAULT nextval('tickets_ticket_seq_seq')");

            DB::statement("
                UPDATE tickets
                SET ticket_number = 'T' || to_char(created_at, 'YY') || '-' || lpad(ticket_seq::text, 5, '0')
                WHERE ticket_number IS NULL
            ");

            DB::statement('ALTER TABLE tickets ALTER COLUMN ticket_seq SET NOT NULL');
            DB::statement('ALTER TABLE tickets ALTER COLUMN ticket_number SET NOT NULL');
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->unique('ticket_seq', 'uq_tickets_ticket_seq');
            $table->unique('ticket_number', 'uq_tickets_ticket_number');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique('uq_tickets_ticket_seq');
            $table->dropUnique('uq_tickets_ticket_number');
            $table->dropColumn(['ticket_number', 'ticket_seq']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS tickets_ticket_seq_seq');
        }
    }
};
