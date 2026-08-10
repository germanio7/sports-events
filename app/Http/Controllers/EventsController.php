<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StreamedApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    public function __construct(private readonly StreamedApi $streamed) {}

    public function index(Request $request): JsonResponse
    {
        $events = $this->streamed->getEvents(
            sport: $request->query('sport'),
            live: filter_var($request->query('live'), FILTER_VALIDATE_BOOLEAN),
            popular: filter_var($request->query('popular'), FILTER_VALIDATE_BOOLEAN),
        );

        return response()->json($events->values());
    }

    public function stream(Request $request): JsonResponse
    {
        $request->validate([
            'source' => ['required', 'string'],
            'id' => ['required', 'string'],
        ]);

        $streams = $this->streamed->getStream(
            source: $request->string('source')->toString(),
            id: $request->string('id')->toString(),
        );

        return response()->json($streams);
    }

    public function sports(): JsonResponse
    {
        return response()->json($this->streamed->getSports());
    }
}
