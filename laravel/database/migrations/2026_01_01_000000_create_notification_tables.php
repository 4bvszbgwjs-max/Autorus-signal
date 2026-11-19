<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->integer('id')->default(0);
            $table->string('name', 200);
            $table->string('sender', 200);
            $table->text('config');
            $this->auditColumns($table);
        });

        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->smallInteger('messageType')->nullable();
            $table->jsonb('channelsFirstOf');
            $table->jsonb('channelsEveryOf');
            $table->boolean('byDefault')->default(false);
            $this->auditColumns($table);
        });

        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 200)->unique();
            $table->string('name', 200)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('applicationFirebaseToken', 200)->nullable();
            $table->boolean('isSubscribe')->nullable()->default(false);
            $table->boolean('isConfirmed')->nullable()->default(false);
            $table->boolean('isPhoneConfirmed')->nullable()->default(false);
            $this->auditColumns($table);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('eventId', 200)->unique();
            $table->timestampTz('expire')->index();
            $table->timestampTz('mustBeSend');
            $table->string('subject', 200)->default('');
            $table->string('recipientId', 200)->index();
            $table->text('content')->nullable()->default('');
            $table->string('shortContent', 200)->nullable()->default('');
            $table->text('htmlContent')->nullable()->default('');
            $table->smallInteger('status')->default(0);
            $table->smallInteger('type')->nullable()->default(0);
            $table->jsonb('channels');
            $table->jsonb('tags');
            $table->jsonb('metadata');
            $table->foreignUuid('scenario_id')->nullable()->references('uuid')->on('scenarios')->nullOnDelete();
            $this->auditColumns($table);
        });

        Schema::create('sendings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 200)->index();
            $table->timestampTz('expire')->index();
            $table->timestampTz('mustBeSend');
            $table->string('subject', 200)->default('');
            $table->text('content')->nullable()->default('');
            $table->string('destination', 200)->default('');
            $table->string('channel', 50);
            $table->smallInteger('status')->default(0);
            $table->foreignId('message')->nullable()->constrained('messages')->nullOnDelete();
            $this->auditColumns($table);
        });
    }

    public function down(): void
    {
        foreach (['sendings', 'messages', 'recipients', 'scenarios', 'channels'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function auditColumns(Blueprint $table): void
    {
        $table->timestampTz('createdAt');
        $table->timestampTz('updatedAt');
        $table->boolean('isDeleted')->default(false);
    }
};
