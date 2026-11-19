<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sendings', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('retryAt')->nullable();
            $table->timestampTz('claimedAt')->nullable();
            $table->string('deliveryError', 80)->nullable();
            $table->index(['status', 'mustBeSend']);
        });
    }

    public function down(): void
    {
        Schema::table('sendings', function (Blueprint $table) {
            $table->dropIndex(['status', 'mustBeSend']);
            $table->dropColumn(['attempts', 'retryAt', 'claimedAt', 'deliveryError']);
        });
    }
};
