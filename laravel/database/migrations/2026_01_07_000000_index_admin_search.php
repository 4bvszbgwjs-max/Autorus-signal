<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'messages' => [
            'messages_admin_subject_trgm_idx' => 'subject',
            'messages_admin_event_id_trgm_idx' => 'eventId',
            'messages_admin_recipient_id_trgm_idx' => 'recipientId',
        ],
        'sendings' => [
            'sendings_admin_subject_trgm_idx' => 'subject',
            'sendings_admin_destination_trgm_idx' => 'destination',
            'sendings_admin_uuid_trgm_idx' => 'uuid',
        ],
    ];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
        $operatorSchema = DB::scalar(<<<'SQL'
            SELECT quote_ident(n.nspname)
            FROM pg_extension AS e
            JOIN pg_namespace AS n ON n.oid = e.extnamespace
            WHERE e.extname = 'pg_trgm'
            SQL) ?? 'public';

        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $index => $column) {
                $valid = DB::scalar('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', ['public.'.$index]);
                // An interrupted concurrent build may leave an invalid index that would block retries.
                if ($valid === false) {
                    DB::statement("DROP INDEX CONCURRENTLY public.$index");
                }
                DB::statement(<<<SQL
                    CREATE INDEX CONCURRENTLY IF NOT EXISTS $index
                    ON public.$table USING gin ("$column" $operatorSchema.gin_trgm_ops)
                    SQL);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $indexes) {
            foreach (array_keys($indexes) as $index) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS public.$index");
            }
        }
    }
};
