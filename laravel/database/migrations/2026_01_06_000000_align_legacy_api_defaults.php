<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('name', 200)->nullable()->default('')->change();
            $table->string('email', 200)->nullable()->default('')->change();
        });

        $this->deliveryDatePrecision(6);
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('name', 200)->nullable()->change();
            $table->string('email', 200)->nullable()->change();
        });

        $this->deliveryDatePrecision(0);
    }

    private function deliveryDatePrecision(int $precision): void
    {
        foreach (['messages', 'sendings'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($precision) {
                $table->timestampTz('expire', $precision)->change();
                $table->timestampTz('mustBeSend', $precision)->change();
            });
        }
    }
};
