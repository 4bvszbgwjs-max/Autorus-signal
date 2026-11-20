<?php

use App\Notifications\AdminSpreadsheet;
use App\Notifications\AdminTransfer;
use App\Notifications\Resource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, "Use APP_ENV=testing with a migrated test PostgreSQL database.\n");
    exit(1);
}

$sheets = $app->make(AdminSpreadsheet::class);
$transfer = $app->make(AdminTransfer::class);
$csv = tempnam(sys_get_temp_dir(), 'signal-test-');

DB::beginTransaction();
try {
    file_put_contents($csv, "\xEF\xBB\xBF\n\nphone;name\n0123;Тест\n");
    $rows = $sheets->read(Resource::Recipient, $csv, 'csv');
    if (count($rows) !== 1 || $rows[0]->phone !== '0123' || $rows[0]->name !== 'Тест') {
        throw new RuntimeException('CSV with blank lines before the header was parsed incorrectly.');
    }
    file_put_contents($csv, "phone,name\r\n0123,\"Строка 1\nСтрока 2 \"\"текст\"\"\"\r\n");
    $rows = $sheets->read(Resource::Recipient, $csv, 'csv');
    if (count($rows) !== 1 || $rows[0]->name !== "Строка 1\nСтрока 2 \"текст\"") {
        throw new RuntimeException('CSV did not preserve legitimate quoted multiline data.');
    }
    file_put_contents($csv, "phone,name\n0123,Первый\n0124,\"Обрезанная запись");
    try {
        $sheets->read(Resource::Recipient, $csv, 'csv');
        throw new RuntimeException('A truncated CSV was accepted.');
    } catch (ValidationException $error) {
        if (! str_contains($error->getMessage(), 'Запись CSV 3')) {
            throw $error;
        }
    }
    $recipientId = Resource::Recipient->query()->insertGetId([
        'phone' => Str::random(32), 'createdAt' => now(), 'updatedAt' => now(),
    ]);
    $xlsx = $sheets->writeXlsx(Resource::Recipient);
    $reader = new Reader;
    $reader->open($xlsx);
    try {
        $rows = $reader->getSheetIterator()->current()->getRowIterator();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->toArray()[0];
        }
        if (! in_array((string) $recipientId, $ids, true)) {
            throw new RuntimeException('XLSX exported an identifier without preserving its text type.');
        }
    } finally {
        $reader->close();
        unlink($xlsx);
    }

    $message = $transfer->save(Resource::Message, [
        'eventId' => (string) Str::uuid(), 'metadata' => (object) ['email' => 'test@example.invalid'],
        'content' => str_repeat('Я', 32_768),
    ]);
    if ($message['recipientId'] !== '' || $transfer->import(Resource::Message, [(object) $message]) !== 1) {
        throw new RuntimeException('Could not reimport a message with no recipient ID.');
    }
    try {
        $unexpected = $sheets->writeXlsx(Resource::Message);
        unlink($unexpected);
        throw new RuntimeException('An overlong XLSX cell was silently truncated.');
    } catch (ValidationException $error) {
        if (! str_contains($error->getMessage(), 'CSV или JSON')) {
            throw $error;
        }
    }
} finally {
    DB::rollBack();
    unlink($csv);
}

echo "Admin spreadsheet and transfer regression checks passed.\n";
