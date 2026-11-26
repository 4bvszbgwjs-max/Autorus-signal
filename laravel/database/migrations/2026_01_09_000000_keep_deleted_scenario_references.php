<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropForeign(['scenario_id']));
    }

    public function down(): void
    {
        // Restore the old foreign key's null-on-delete behavior for historical references.
        DB::table('messages')->whereNotNull('scenario_id')
            ->whereNotExists(fn ($query) => $query->from('scenarios')->whereColumn('scenarios.uuid', 'messages.scenario_id'))
            ->update(['scenario_id' => null]);

        Schema::table('messages', fn (Blueprint $table) => $table->foreign('scenario_id')->references('uuid')->on('scenarios')->nullOnDelete());
    }
};
