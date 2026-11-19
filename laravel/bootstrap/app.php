<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
        $middleware->api(append: [\App\Http\ApiAccess::class]);
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/*')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception): bool {
            // QueryException messages may contain notification bodies and credentials in SQL bindings.
            logger()->error('Database operation failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);

            return false;
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = [];
            $failedRules = $exception->validator->failed();
            foreach ($exception->errors() as $field => $messages) {
                $rules = array_keys($failedRules[$field] ?? []);
                foreach ($messages as $index => $message) {
                    $required = ($rules[$index] ?? null) === 'Required';
                    $errors[] = [
                        'code' => $required ? 'required' : 'invalid',
                        'source' => $field,
                        'message' => $required ? 'Обязательное поле.' : $message,
                    ];
                }
            }

            return response()->json(['errors' => $errors], 400);
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();

            return response()->json(['errors' => [[
                'code' => $status === 404 ? 'not_found' : 'error',
                'source' => 'detail',
                'message' => $status === 404 ? 'Not found.' : (\Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error'),
            ]]], $status, $exception->getHeaders());
        });
    })->create();
