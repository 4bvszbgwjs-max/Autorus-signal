<?php

namespace App\Notifications;

use App\Http\JsonPayload;
use DateTimeInterface;
use Generator;
use Illuminate\Validation\ValidationException;
use JsonException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;
use ZipArchive;

final class AdminSpreadsheet
{
    public const XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const MAX_ROWS = 1000;

    private const XLSX_MAX_CELL_LENGTH = 32_767;

    private const JSON_FIELDS = ['config', 'channelsFirstOf', 'channelsEveryOf', 'channels', 'tags', 'metadata'];

    private const BOOL_FIELDS = ['isDeleted', 'isConfirmed', 'isPhoneConfirmed', 'isSubscribe', 'byDefault'];

    private const CSV_ESCAPE = '/^(?:[\x00-\x20]*[=+@\-＝＋－＠]|[\t\r\n\x27])/u';

    public function read(Resource $resource, string $path, string $format): array
    {
        $columns = $this->headers($resource);
        $table = $format === 'csv' ? $this->csv($path) : $this->xlsx($path);
        try {
            $header = [];
            $records = [];
            foreach ($table as $row) {
                if ($header === []) {
                    $header = array_map(fn ($name) => is_string($name) ? trim($name) : '', $row);
                    if ($resource === Resource::Message) {
                        $header = array_map(fn ($name) => $name === 'scenario' ? 'scenario_id' : $name, $header);
                    }
                    if ($header === [] || array_diff($header, $columns) !== [] || count(array_unique($header)) !== count($header)) {
                        $this->invalid('Проверьте названия и дубликаты столбцов. Образец можно скачать через экспорт.');
                    }
                    continue;
                }

                $index = count($records) + 1;
                if ($index > self::MAX_ROWS) {
                    $this->invalid('В одном файле может быть до 1000 записей.');
                }
                if (array_diff_key($row, $header) !== []) {
                    $this->invalid("Строка $index: значений больше, чем столбцов.");
                }

                $record = [];
                foreach ($row as $position => $value) {
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $field = $header[$position];
                    try {
                        $record[$field] = $this->value($field, $value, $format);
                    } catch (JsonException) {
                        $this->invalid("Строка $index: неверный JSON в столбце $field.");
                    }
                }
                if ($record !== []) {
                    $records[] = (object) $record;
                }
            }
            if ($header === []) {
                $this->invalid('В таблице отсутствует строка заголовков.');
            }

            return JsonPayload::objects(json_encode($records, JSON_THROW_ON_ERROR));
        } catch (OpenSpoutException | JsonException) {
            $this->invalid('Проверьте формат таблицы и кодировку UTF-8.');
        }
    }

    public function writeCsv(Resource $resource): void
    {
        $output = fopen('php://output', 'wb');
        try {
            fwrite($output, "\xEF\xBB\xBF");
            foreach ($this->rows($resource) as $row) {
                fputcsv($output, array_map(static function ($value): string {
                    if (is_bool($value)) {
                        return $value ? 'true' : 'false';
                    }
                    $text = (string) $value;

                    return is_string($value) && preg_match(self::CSV_ESCAPE, $text) ? "'".$text : $text;
                }, $row), escape: '');
            }
        } finally {
            fclose($output);
        }
    }

    public function writeXlsx(Resource $resource): string
    {
        $path = tempnam(sys_get_temp_dir(), 'signal-xlsx-');
        $writer = new Writer;
        try {
            $writer->openToFile($path);
            try {
                foreach ($this->rows($resource) as $index => $row) {
                    $cells = [];
                    foreach ($row as $value) {
                        if (is_string($value) && strlen($value) > self::XLSX_MAX_CELL_LENGTH
                            && strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > 2 * self::XLSX_MAX_CELL_LENGTH) {
                            $this->invalid('Строка '.($index + 1).' содержит текст длиннее лимита Excel (32 767 символов). Выберите CSV или JSON.');
                        }
                        $cells[] = is_string($value) ? new StringCell($value) : Cell::fromValue($value);
                    }
                    $writer->addRow(new Row($cells));
                }
            } finally {
                $writer->close();
            }
        } catch (Throwable $exception) {
            unlink($path);
            throw $exception;
        }

        return $path;
    }

    private function columns(Resource $resource): array
    {
        // Use the same service fields even when a table is empty.
        return match ($resource) {
            Resource::Channel => ['id', 'code', 'name', 'sender', 'config'],
            Resource::Scenario => ['id', 'uuid', 'title', 'description', 'messageType', 'channelsFirstOf', 'channelsEveryOf', 'byDefault'],
            Resource::Recipient => ['id', 'phone', 'name', 'email', 'applicationFirebaseToken', 'isSubscribe', 'isConfirmed', 'isPhoneConfirmed'],
            Resource::Message => ['id', 'uuid', 'eventId', 'expire', 'mustBeSend', 'subject', 'recipientId', 'content', 'shortContent', 'htmlContent', 'status', 'type', 'channels', 'tags', 'metadata', 'scenario_id'],
            Resource::Sending => ['id', 'uuid', 'expire', 'mustBeSend', 'subject', 'content', 'destination', 'channel', 'status', 'message'],
            Resource::EventLog => ['id', 'timestamp', 'event', 'initiator', 'tracing'],
        };
    }

    private function rows(Resource $resource): Generator
    {
        $columns = $this->headers($resource);
        yield $columns;
        foreach ($resource->query()->lazyById(250, $resource->key()) as $row) {
            $record = $resource->serialize($row);
            yield array_map(static function (string $name) use ($record) {
                $value = $record[$name] ?? null;
                if ($value !== null && in_array($name, ['id', 'message'], true)) {
                    return (string) $value;
                }

                return is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $value;
            }, $columns);
        }
    }

    private function headers(Resource $resource): array
    {
        $columns = $this->columns($resource);

        return $resource === Resource::EventLog ? $columns : [...$columns, 'createdAt', 'updatedAt', 'isDeleted'];
    }

    private function csv(string $path): Generator
    {
        $file = fopen($path, 'rb');
        try {
            if (fread($file, 3) !== "\xEF\xBB\xBF") {
                rewind($file);
            }
            do {
                $start = ftell($file);
                $header = fgetcsv($file, escape: '');
            } while ($header === [null]);
            $delimiter = count($header ?: []) === 1 && str_contains($header[0] ?? '', ';') ? ';' : ',';
            fseek($file, $start);
            $record = 0;
            while (true) {
                $start = ftell($file);
                $row = fgetcsv($file, separator: $delimiter, escape: '');
                if ($row === false) {
                    break;
                }
                $length = ftell($file) - $start;
                fseek($file, $start);
                // fgetcsv also returns values when a quoted field reaches EOF without a closing quote.
                if (substr_count(fread($file, $length), '"') % 2 !== 0) {
                    $this->invalid('Запись CSV '.($record + 1).' содержит незакрытую кавычку.');
                }
                if ($row !== [null]) {
                    $record++;
                    yield $row;
                }
            }
        } finally {
            fclose($file);
        }
    }

    private function xlsx(string $path): Generator
    {
        $this->checkZip($path);
        $reader = new Reader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->cells as $cell) {
                        if ($cell instanceof ErrorCell) {
                            $this->invalid('Исправьте ячейки с ошибками Excel.');
                        }
                    }
                    // FormulaCell::getValue() returns literal text, never the cached calculation.
                    yield $row->toArray();
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function checkZip(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->invalid('Файл XLSX повреждён или защищён паролем.');
        }
        try {
            if ($zip->numFiles > 256 || $zip->locateName('[Content_Types].xml') === false) {
                $this->invalid('Загрузите обычный файл XLSX с одним листом данных.');
            }
            $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false || ($size += $entry['size']) > 24 * 1024 * 1024) {
                    $this->invalid('Размер распакованного XLSX превышает 24 МБ.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function value(string $field, mixed $value, string $format): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (! is_scalar($value)) {
            $this->invalid("Столбец $field содержит неподдерживаемое значение.");
        }
        if ($format === 'csv' && is_string($value) && str_starts_with($value, "'")
            && preg_match(self::CSV_ESCAPE, substr($value, 1))) {
            $value = substr($value, 1);
        }
        if (in_array($field, self::JSON_FIELDS, true)) {
            return json_decode((string) $value, flags: JSON_THROW_ON_ERROR);
        }
        if (in_array($field, self::BOOL_FIELDS, true)) {
            return match (strtolower((string) $value)) {
                'true', '1' => true,
                'false', '0' => false,
                default => $value,
            };
        }

        return (string) $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['records' => $message]);
    }
}
