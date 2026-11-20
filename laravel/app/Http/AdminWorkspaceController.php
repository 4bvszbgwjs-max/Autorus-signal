<?php

namespace App\Http;

use App\Admin\AdminAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminWorkspaceController
{
    public function accounts(AdminAccounts $accounts)
    {
        return view('admin', [
            'resource' => null, 'panel' => 'accounts', 'panelTitle' => 'Учётные записи',
            'accounts' => $accounts->query()->select('id', 'login', 'role', 'active')->orderBy('login')->paginate(25),
            'roles' => AdminAccounts::ROLES,
        ]);
    }

    public function accountForm(AdminAccounts $accounts, ?string $id = null)
    {
        $id = $this->accountId($id);
        return view('admin', [
            'resource' => null, 'panel' => 'account-form', 'panelTitle' => 'Учётная запись',
            'account' => $id === null ? null : ($accounts->query()->select('id', 'login', 'role', 'active')->find($id) ?? abort(404)),
            'roles' => AdminAccounts::ROLES,
        ]);
    }

    public function saveAccount(Request $request, AdminAccounts $accounts, ?string $id = null)
    {
        $id = $this->accountId($id);
        $actor = $request->attributes->get('admin');
        try {
            $accounts->save($request->only(['login', 'role', 'active', 'password', 'password_confirmation']), $actor->login, $id, $actor->id ?? null);
        } catch (ValidationException $error) {
            $draft = array_filter($request->only(['login', 'role', 'active']), 'is_string');

            return back()->withErrors($error->errors())->withInput($draft);
        }

        $route = $accounts->identity($request) === null ? 'admin.login' : 'admin.accounts';

        return redirect()->route($route)->with('status', 'Учётная запись сохранена.');
    }

    private function accountId(?string $id): ?int
    {
        if ($id === null) {
            return null;
        }
        $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($number === false, 404);

        return $number;
    }

    public function audit()
    {
        return view('admin', [
            'resource' => null, 'panel' => 'audit', 'panelTitle' => 'Журнал действий',
            'audit' => DB::table('admin_audit')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function queue()
    {
        $now = now();
        $pending = DB::select(<<<'SQL'
            WITH clock AS (SELECT ?::timestamptz AS at), scheduled AS (
                SELECT s.channel, s.status, s.destination, clock.at,
                       greatest(s."createdAt", s."mustBeSend", s."retryAt") AS due_at
                FROM sendings AS s CROSS JOIN clock
                LEFT JOIN messages AS m ON m.id = s.message
                WHERE s.status IN (0, 1, 2) AND NOT s."isDeleted"
                  AND s.expire > clock.at AND NOT coalesce(m."isDeleted", false)
            )
            SELECT channel,
                   count(*) FILTER (WHERE status < 2) AS waiting,
                   count(*) FILTER (WHERE status < 2 AND destination <> '' AND due_at <= at) AS ready,
                   count(*) FILTER (WHERE status < 2 AND destination = '') AS missing_address,
                   count(*) FILTER (WHERE status = 2) AS in_progress,
                   max(extract(epoch FROM (at - due_at))) FILTER
                       (WHERE status < 2 AND destination <> '' AND due_at <= at) AS oldest_seconds
            FROM scheduled GROUP BY channel ORDER BY channel
            SQL, [$now]);
        $failures = DB::table('sendings')->select('channel', 'deliveryError')
            ->selectRaw('count(*) AS total')->whereNotNull('deliveryError')
            ->where('updatedAt', '>=', $now->copy()->subDay())
            ->groupBy('channel', 'deliveryError')->orderBy('channel')->orderByDesc('total')->get();

        return view('admin', [
            'resource' => null, 'panel' => 'queue', 'panelTitle' => 'Очередь доставки',
            'queue' => collect($pending), 'failures' => $failures,
        ]);
    }
}
