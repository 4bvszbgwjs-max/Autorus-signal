<?php

namespace App\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminAccounts
{
    public const ROLES = ['viewer' => 'Наблюдатель', 'operator' => 'Оператор', 'admin' => 'Администратор'];

    private const DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function query(): Builder
    {
        return DB::table('admin_accounts');
    }

    public function requiresLogin(): bool
    {
        return $this->query()->exists();
    }

    public function identity(Request $request): ?object
    {
        $session = $request->session()->get('signal_admin');
        if (is_array($session) && isset($session['id'], $session['signature'])) {
            $account = $this->query()->where('id', $session['id'])->where('active', true)->first();
            if ($account !== null && hash_equals($this->signature($account), $session['signature'])) {
                unset($account->password_hash, $account->session_version);

                return $account;
            }

            return null;
        }

        $hash = config('homer.admin_password_hash');
        $signature = $request->session()->get('homer_admin');
        if (is_string($hash) && $hash !== '' && is_string($signature)
            && hash_equals(hash('sha256', $hash), $signature) && ! $this->requiresLogin()) {
            return (object) ['login' => 'legacy', 'role' => 'admin'];
        }

        return null;
    }

    public function authenticate(Request $request, ?string $login, string $password): bool
    {
        if (! $this->requiresLogin()) {
            $hash = config('homer.admin_password_hash');
            if (! is_string($hash) || $hash === '' || ! password_verify($password, $hash)) {
                return false;
            }
            $request->session()->regenerate(true);
            $request->session()->forget('signal_admin');
            $request->session()->put('homer_admin', hash('sha256', $hash));

            return true;
        }

        if (strlen($password) > 72 || str_contains($password, "\0")) {
            return false;
        }
        $account = $this->query()->where('login', strtolower($login ?? ''))->first();
        $matches = password_verify($password, $account->password_hash ?? self::DUMMY_HASH);
        if (! $matches || ! $account?->active) {
            return false;
        }

        $request->session()->regenerate(true);
        $request->session()->forget('homer_admin');
        $request->session()->put('signal_admin', ['id' => $account->id, 'signature' => $this->signature($account)]);

        return true;
    }

    public function save(array $input, string $actor, ?int $id = null, ?int $actorId = null): void
    {
        $input['login'] = is_string($input['login'] ?? null) ? strtolower($input['login']) : null;
        $data = Validator::make($input, [
            'login' => ['required', 'alpha_dash:ascii', 'max:64', Rule::notIn(['legacy', 'console'])],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'active' => 'required|boolean',
            'password' => ($id === null ? 'required' : 'nullable').'|string|min:12|confirmed',
        ])->validate();
        $data['active'] = (bool) $data['active'];

        $password = $data['password'] ?? null;
        if ($password !== null && (strlen($password) > 72 || str_contains($password, "\0"))) {
            throw ValidationException::withMessages(['password' => 'Пароль должен занимать не более 72 байт и не содержать нулевых символов.']);
        }
        unset($data['password']);
        if ($password !== null) {
            $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        DB::transaction(function () use ($data, $actor, $id, $actorId): void {
            // Account edits are rare. Serialize the check that keeps one active administrator.
            DB::statement('lock table admin_accounts in share row exclusive mode');
            $others = $this->query()->when($id !== null, fn (Builder $query) => $query->where('id', '!=', $id));
            if ((clone $others)->where('login', $data['login'])->exists()) {
                throw ValidationException::withMessages(['login' => 'Этот логин уже используется.']);
            }
            if (($data['role'] !== 'admin' || ! $data['active'])
                && ! $others->where('role', 'admin')->where('active', true)->exists()) {
                throw ValidationException::withMessages(['role' => 'В системе должен остаться один активный администратор или больше.']);
            }
            if ($id === null) {
                $data['session_version'] = (string) Str::uuid();
                $key = $this->query()->insertGetId($data);
            } else {
                $account = $this->query()->where('id', $id);
                $previous = (clone $account)->first() ?? abort(404);
                if ($data['login'] !== $previous->login || $data['role'] !== $previous->role
                    || $data['active'] !== $previous->active || isset($data['password_hash'])) {
                    $data['session_version'] = (string) Str::uuid();
                }
                $account->update($data);
                $key = $id;
            }
            self::audit($actor, $id === null ? 'create' : 'update', 'account', (string) $key, actorId: $actorId);
        }, 3);
    }

    public static function audit(string $actor, string $action, string $resource, ?string $reference = null, ?int $count = null, ?int $actorId = null): void
    {
        DB::table('admin_audit')->insert([
            'occurred_at' => now(), 'actor' => $actor, 'actor_id' => $actorId, 'action' => $action,
            'resource' => $resource, 'reference' => $reference, 'record_count' => $count,
        ]);
    }

    private function signature(object $account): string
    {
        return hash('sha256', $account->session_version);
    }
}
