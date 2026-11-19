<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sendings', function (Blueprint $table) {
            $table->foreign('channel')->references('code')->on('channels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sendings', function (Blueprint $table) {
            $table->dropForeign(['channel']);
        });
    }
};
