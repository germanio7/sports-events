<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Visit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackVisits
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->method() === 'GET') {
            Visit::query()->firstOrCreate(
                ['ip' => $request->ip(), 'date' => now()->toDateString()],
            );
        }

        return $response;
    }
}
