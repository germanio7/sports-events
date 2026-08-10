<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PelotaLibreScraper;
use Illuminate\Http\JsonResponse;

class PelotaController extends Controller
{
    public function __construct(private readonly PelotaLibreScraper $scraper) {}

    public function agenda(): JsonResponse
    {
        return response()->json([
            'source' => 'pelotaalibre.st/agenda.php',
            'events' => $this->scraper->getAgenda()->values(),
        ]);
    }
}
