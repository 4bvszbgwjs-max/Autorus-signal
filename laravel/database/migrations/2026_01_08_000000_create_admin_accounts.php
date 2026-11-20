<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('login', 64)->unique();
            $table->string('password_hash');
            $table->string('role', 16);
            $table->boolean('active')->default(true);
            $table->uuid('session_version');
        });
        Schema::create('admin_audit', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at');
            $table->string('actor', 64);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 30);
            $table->string('resource', 30);
            $table->string('reference', 64)->nullable();
            $table->unsignedInteger('record_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit');
        Schema::dropIfExists('admin_accounts');
    }
};
