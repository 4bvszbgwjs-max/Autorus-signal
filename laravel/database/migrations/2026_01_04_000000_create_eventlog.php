<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventlog', function (Blueprint $table) {
            $table->id();
            $table->string('initiator', 250);
            $table->timestampTz('timestamp')->index();
            $table->integer('level')->default(0);
            $table->text('event')->default('');
            $table->text('tracing')->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventlog');
    }
};
