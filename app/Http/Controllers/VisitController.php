<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Visit;
use Illuminate\Http\JsonResponse;

class VisitController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'today' => Visit::query()->whereDate('date', today())->sum('count'),
            'total' => Visit::query()->sum('count'),
        ]);
    }
}
