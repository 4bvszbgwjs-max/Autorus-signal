<?php

namespace App\Http;

use App\Admin\AdminAccounts;
use Closure;
use Illuminate\Http\Request;

final class AdminAccess
{
    public function __construct(private readonly AdminAccounts $accounts) {}

    public function handle(Request $request, Closure $next, string $permission = 'read')
    {
        $identity = $this->accounts->identity($request);
        if ($identity === null && $permission === 'list') {
            return redirect()->route('admin.login');
        }
        abort_unless($identity !== null, 403);
        abort_if($permission === 'write' && $identity->role === 'viewer', 403);
        abort_if($permission === 'admin' && $identity->role !== 'admin', 403);
        $request->attributes->set('admin', $identity);

        return $next($request);
    }
}
