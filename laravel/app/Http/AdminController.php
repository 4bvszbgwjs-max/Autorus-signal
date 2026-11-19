<?php

namespace App\Http;

use App\Notifications\AdminSpreadsheet;
use App\Notifications\AdminTransfer;
use App\Notifications\Directory;
use App\Notifications\Resource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AdminController
{
    private const SEARCH = ['recipient' => ['name', 'phone', 'email'], 'channel' => ['code', 'name'], 'scenario' => ['title', 'description'],
        'message' => ['subject', 'eventId', 'recipientId'], 'sending' => ['subject', 'destination', 'uuid'], 'eventlog' => ['event', 'initiator']];

    public function login(Request $request)
    {
        return $this->signedIn($request)
            ? redirect()->route('admin.directory', ['resource' => 'channel'])
            : view('admin', ['resource' => null]);
    }

    public function authenticate(Request $request)
    {
        $credentials = $request->validate(['password' => 'required|string|max:1024']);
        $hash = config('homer.admin_password_hash');
        if (! is_string($hash) || ! password_verify($credentials['password'], $hash)) {
            return back()->withErrors(['password' => 'Неверный пароль.']);
        }

        $request->session()->regenerate();
        $request->session()->put('homer_admin', hash('sha256', $hash));

        return redirect()->route('admin.directory', ['resource' => 'channel']);
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function index(Request $request, string $resource)
    {
        if (! $this->signedIn($request)) {
            return redirect()->route('admin.login');
        }

        $type = Resource::from($resource);
        $query = $type->query()->orderByDesc($type->key());
        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where(function ($filter) use ($resource, $search): void {
                foreach (self::SEARCH[$resource] as $field) {
                    $filter->orWhereLike($field, '%'.mb_substr($search, 0, 200).'%');
                }
            });
        }
        $status = $request->query('status');
        if (in_array($type, [Resource::Message, Resource::Sending], true)
            && is_string($status) && preg_match('/^[0-8]$/D', $status)) {
            $query->where('status', (int) $status);
        }
        $rows = $query->paginate(25)->withQueryString()->through(fn (object $row) => $this->present($type, $row));

        return view('admin', ['resource' => $type, 'rows' => $rows]);
    }

    public function form(Request $request, Directory $directory, string $resource, ?string $key = null)
    {
        abort_unless($this->signedIn($request), 403);
        $type = Resource::from($resource);

        return view('admin', [
            'resource' => $type,
            'key' => $key,
            'record' => $key === null ? [] : $this->present($type, $directory->find($type, $key)),
        ]);
    }

    public function save(Request $request, AdminTransfer $transfer, string $resource, ?string $key = null)
    {
        abort_unless($this->signedIn($request), 403);
        $type = Resource::from($resource);
        $this->validateKey($type, $key);
        try {
            $transfer->save($type, $this->formData($request, $type), $key);
        } catch (JsonException) {
            return back()->withErrors(['payload' => 'Проверьте формат JSON в настройках записи.']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('admin.directory', ['resource' => $resource])->with('status', 'Запись сохранена.');
    }

    public function export(Request $request, AdminSpreadsheet $sheets, string $resource)
    {
        abort_unless($this->signedIn($request), 403);
        $type = Resource::from($resource);
        $format = $request->query('format', 'json');
        abort_unless(in_array($format, ['json', 'csv', 'xlsx'], true), 400);
        $filename = "signal-$resource.$format";
        $headers = ['Cache-Control' => 'no-store'];

        if ($format === 'csv') {
            return response()->streamDownload(fn () => $sheets->writeCsv($type), $filename, $headers + ['Content-Type' => 'text/csv; charset=utf-8']);
        }
        if ($format === 'xlsx') {
            try {
                $path = $sheets->writeXlsx($type);
            } catch (ValidationException $exception) {
                return redirect()->route('admin.directory', ['resource' => $resource])->withErrors($exception->errors());
            }

            return response()->download($path, $filename, $headers + ['Content-Type' => AdminSpreadsheet::XLSX_TYPE])->deleteFileAfterSend();
        }

        return response()->streamDownload(function () use ($type): void {
            echo "[\n";
            $separator = '';
            foreach ($type->query()->lazyById(250, $type->key()) as $row) {
                echo $separator.json_encode($type->serialize($row), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $separator = ",\n";
            }
            echo "\n]\n";
        }, $filename, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function import(Request $request, AdminTransfer $transfer, AdminSpreadsheet $sheets, string $resource)
    {
        abort_unless($this->signedIn($request), 403);
        $request->validate(['records' => 'required|file|max:2048']);
        try {
            $file = $request->file('records');
            $type = Resource::from($resource);
            $rows = match (strtolower($file->getClientOriginalExtension())) {
                'json' => JsonPayload::objects($file->get()),
                'csv', 'xlsx' => $sheets->read($type, $file->getPathname(), strtolower($file->getClientOriginalExtension())),
                default => throw ValidationException::withMessages(['records' => 'Загрузите файл JSON, CSV или XLSX.']),
            };
            if (count($rows) > 1000) {
                throw ValidationException::withMessages(['records' => 'В одном файле может быть до 1000 записей.']);
            }
            $count = $transfer->import($type, $rows);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('status', 'Импортировано записей: '.$count);
    }

    public function delete(Request $request, AdminTransfer $transfer, string $resource, string $key)
    {
        abort_unless($this->signedIn($request), 403);
        $type = Resource::from($resource);
        $this->validateKey($type, $key);
        $transfer->remove($type, $key);

        return redirect()->route('admin.directory', ['resource' => $resource])->with('status', 'Запись удалена.');
    }

    private function validateKey(Resource $resource, ?string $key): void
    {
        abort_if($key !== null && $resource->key() === 'id' && filter_var($key, FILTER_VALIDATE_INT) === false, 404);
    }

    private function present(Resource $resource, object $row): array
    {
        $data = $resource->serialize($row);
        if ($resource === Resource::Sending) {
            $data += ['attempts' => $row->attempts, 'deliveryError' => $row->deliveryError, 'retryAt' => $row->retryAt];
        }

        return $data;
    }

    private function formData(Request $request, Resource $resource): array
    {
        if (is_string($request->input('payload'))) {
            abort_if(strlen($request->input('payload')) > 65536, 413);

            return $resource === Resource::Message
                ? get_object_vars(JsonPayload::singleObject($request->input('payload')))
                : JsonPayload::object($request->input('payload'));
        }

        $fields = $request->validate(['fields' => 'required|array'])['fields'];
        $schema = config("homer_admin.resources.{$resource->value}.fields") + ['isDeleted' => ['Пометка удаления', 'bool']];
        $result = [];
        foreach ($schema as $name => [, $type]) {
            $value = $fields[$name] ?? null;
            if (! is_string($value) || $value === '' || $type === 'readonly') {
                continue;
            }
            $result[$name] = match ($type) {
                'bool' => $value === '1',
                'json' => json_decode($value, $resource !== Resource::Message, flags: JSON_THROW_ON_ERROR),
                default => $value,
            };
        }

        JsonPayload::objects(json_encode((object) $result, JSON_THROW_ON_ERROR));

        return $result;
    }

    private function signedIn(Request $request): bool
    {
        $hash = config('homer.admin_password_hash');
        $session = $request->session()->get('homer_admin');

        return is_string($hash) && $hash !== '' && is_string($session)
            && hash_equals(hash('sha256', $hash), $session);
    }
}
