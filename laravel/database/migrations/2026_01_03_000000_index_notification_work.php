<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX messages_preparation_idx ON messages (id) WHERE status = 0 AND NOT "isDeleted"');
        Schema::table('sendings', function (Blueprint $table) {
            $table->index(['message', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sendings', function (Blueprint $table) {
            $table->dropIndex(['message', 'status']);
        });
        DB::statement('DROP INDEX messages_preparation_idx');
    }
};
