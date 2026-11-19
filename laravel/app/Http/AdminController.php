<?php

namespace App\Http;

use App\Notifications\Directory;
use App\Notifications\Resource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AdminController
{
    private const EDITABLE = ['channel', 'scenario', 'recipient'];

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
        $rows = $type->query()->orderByDesc($type->key())->paginate(25);

        return view('admin', [
            'resource' => $type,
            'rows' => $rows->through(function (object $row) use ($type): array {
                $data = $type->serialize($row);
                if ($type === Resource::Sending) {
                    $data['attempts'] = $row->attempts;
                    $data['deliveryError'] = $row->deliveryError;
                    $data['retryAt'] = $row->retryAt;
                }

                return $data;
            }),
            'editable' => in_array($resource, self::EDITABLE, true),
            'templates' => [
                'channel' => ['code' => '', 'name' => '', 'sender' => Directory::SENDERS[0]],
                'scenario' => ['messageType' => 0, 'byDefault' => false, 'channelsFirstOf' => [], 'channelsEveryOf' => []],
                'recipient' => ['phone' => '', 'name' => '', 'email' => ''],
            ],
        ]);
    }

    public function save(Request $request, Directory $directory, string $resource, ?string $key = null)
    {
        abort_unless($this->signedIn($request), 403);
        $type = Resource::from($resource);
        try {
            $payload = $request->input('payload');
            if (! is_string($payload) || strlen($payload) > 65536) {
                throw new JsonException;
            }
            $directory->save($type, JsonPayload::object($payload), $key);
        } catch (JsonException) {
            return back()->withErrors(['payload' => 'Введите JSON-объект размером до 64 КБ.']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('admin.directory', ['resource' => $resource])->with('status', 'Запись сохранена.');
    }

    private function signedIn(Request $request): bool
    {
        $hash = config('homer.admin_password_hash');
        $session = $request->session()->get('homer_admin');

        return is_string($hash) && $hash !== '' && is_string($session)
            && hash_equals(hash('sha256', $hash), $session);
    }
}
