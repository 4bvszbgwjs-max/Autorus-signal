<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PDOException;

final class ReadinessController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::selectOne('select 1');
        } catch (PDOException) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}
