<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PelotaLibreScraper;
use App\Services\PelisJuanitaScraper;
use Illuminate\Http\JsonResponse;

class PelotaController extends Controller
{
    public function __construct(private readonly PelotaLibreScraper $scraper, private readonly PelisJuanitaScraper $juanita) {}

    public function agenda(): JsonResponse
    {
        return response()->json([
            'source' => 'futbollibrehd.me/api/agenda',
            'events' => $this->scraper->getAgenda()->values(),
        ]);
    }

    public function agendaJuanita(): JsonResponse
    {
        return response()->json([
            'source' => 'pelisjuanita.com/tv/api-agenda.php',
            'events' => $this->juanita->getAgenda()->values(),
        ]);
    }
}
