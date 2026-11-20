<?php

use App\Admin\AdminAccounts;
use App\Http\AdminAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});

if (! $app->environment('testing')) {
    fwrite(STDERR, "Use APP_ENV=testing with a migrated, disposable PostgreSQL database.\n");
    exit(1);
}

$accounts = $app->make(AdminAccounts::class);
if ($accounts->requiresLogin()) {
    throw new RuntimeException('The regression requires an empty account directory.');
}
$guard = $app->make(AdminAccess::class);
config(['homer.admin_password_hash' => password_hash('shared-test-password', PASSWORD_BCRYPT)]);
$sessionRequest = function (): Request {
    $request = Request::create('/admin');
    $request->setLaravelSession(new Store('admin-test', new ArraySessionHandler(1)));

    return $request;
};
$credentials = fn (string $login, string $role): array => [
    'login' => $login, 'role' => $role, 'active' => true,
    'password' => 'personal-test-password', 'password_confirmation' => 'personal-test-password',
];

DB::beginTransaction();
try {
    $legacy = $sessionRequest();
    if (! $accounts->authenticate($legacy, null, 'shared-test-password')) {
        throw new RuntimeException('Legacy login failed before creating a personal administrator.');
    }
    $accounts->save($credentials('test-admin', 'admin'), 'console');
    $accounts->save($credentials('test-reader', 'viewer'), 'test-admin');
    if ($accounts->identity($legacy) !== null || $accounts->authenticate($legacy, null, 'shared-test-password')) {
        throw new RuntimeException('Legacy credentials or session remained active.');
    }
    $viewer = $sessionRequest();
    if (! $accounts->authenticate($viewer, 'TEST-READER', 'personal-test-password')) {
        throw new RuntimeException('Personal login failed.');
    }
    $callback = fn () => response('allowed');
    $guard->handle($viewer, $callback, 'read');
    foreach (['write', 'admin'] as $scope) {
        try {
            $guard->handle($viewer, $callback, $scope);
            throw new RuntimeException('A viewer gained '.$scope.' access.');
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }
        }
    }
    $readerId = $accounts->query()->where('login', 'test-reader')->value('id');
    $accounts->save(['login' => 'test-reader', 'role' => 'viewer', 'active' => false], 'test-admin', $readerId);
    if ($accounts->identity($viewer) !== null) {
        throw new RuntimeException('A disabled account kept its session.');
    }
    $accounts->save(['login' => 'test-reader', 'role' => 'viewer', 'active' => true], 'test-admin', $readerId);
    if ($accounts->identity($viewer) !== null) {
        throw new RuntimeException('Reactivating an account restored a revoked session.');
    }
    $secondViewerSession = $sessionRequest();
    if (! $accounts->authenticate($secondViewerSession, 'test-reader', 'personal-test-password')) {
        throw new RuntimeException('Fresh login after reactivation failed.');
    }
    foreach (['operator', 'viewer'] as $role) {
        $accounts->save(['login' => 'test-reader', 'role' => $role, 'active' => true], 'test-admin', $readerId);
        if ($accounts->identity($secondViewerSession) !== null) {
            throw new RuntimeException('Changing a role restored an old session.');
        }
    }
    $adminId = $accounts->query()->where('login', 'test-admin')->value('id');
    try {
        $accounts->save(['login' => 'test-admin', 'role' => 'viewer', 'active' => true], 'test-admin', $adminId);
        throw new RuntimeException('The last active administrator was demoted.');
    } catch (ValidationException $error) {
        if (! array_key_exists('role', $error->errors())) {
            throw $error;
        }
    }
    $entries = DB::table('admin_audit')->where('actor', 'test-admin')->orderBy('id')->pluck('action')->all();
    if ($entries !== ['create', 'update', 'update', 'update', 'update']) {
        throw new RuntimeException('The audit journal did not match committed actions.');
    }
} finally {
    DB::rollBack();
}

echo "Administrative accounts, permissions, revocation and audit checks passed.\n";
