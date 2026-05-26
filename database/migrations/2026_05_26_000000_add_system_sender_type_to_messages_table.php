<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Current allowed: customer, pic, spv, ai
        // Add: system
        if ($driver === 'pgsql') {
            // Laravel `enum()` on PostgreSQL uses a CHECK constraint by default.
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_sender_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_sender_type_check CHECK (sender_type IN ('customer','pic','spv','ai','system'))");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY sender_type ENUM('customer','pic','spv','ai','system') NOT NULL");
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement(<<<'SQL'
CREATE TABLE messages_tmp (
  id TEXT PRIMARY KEY NOT NULL,
  ticket_id TEXT NULL,
  customer_id TEXT NOT NULL,
  sender_type TEXT NOT NULL CHECK (sender_type IN ('customer','pic','spv','ai','system')),
  sender_id TEXT NULL,
  content TEXT NULL,
  wa_message_id VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL
)
SQL);

            DB::statement(<<<'SQL'
INSERT INTO messages_tmp (id, ticket_id, customer_id, sender_type, sender_id, content, wa_message_id, created_at)
SELECT id, ticket_id, customer_id, sender_type, sender_id, content, wa_message_id, created_at FROM messages
SQL);

            DB::statement('DROP TABLE messages');
            DB::statement('ALTER TABLE messages_tmp RENAME TO messages');
            DB::statement('PRAGMA foreign_keys=ON');
            return;
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_sender_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_sender_type_check CHECK (sender_type IN ('customer','pic','spv','ai'))");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY sender_type ENUM('customer','pic','spv','ai') NOT NULL");
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement(<<<'SQL'
CREATE TABLE messages_tmp (
  id TEXT PRIMARY KEY NOT NULL,
  ticket_id TEXT NULL,
  customer_id TEXT NOT NULL,
  sender_type TEXT NOT NULL CHECK (sender_type IN ('customer','pic','spv','ai')),
  sender_id TEXT NULL,
  content TEXT NULL,
  wa_message_id VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL
)
SQL);

            DB::statement(<<<'SQL'
INSERT INTO messages_tmp (id, ticket_id, customer_id, sender_type, sender_id, content, wa_message_id, created_at)
SELECT id, ticket_id, customer_id, sender_type, sender_id, content, wa_message_id, created_at FROM messages
SQL);

            DB::statement('DROP TABLE messages');
            DB::statement('ALTER TABLE messages_tmp RENAME TO messages');
            DB::statement('PRAGMA foreign_keys=ON');
            return;
        }
    }
};
