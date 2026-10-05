<?php

namespace Tests\Unit;

use App\Services\PelotaLibreScraper;
use Tests\TestCase;

class PelotaLibreScraperTest extends TestCase
{
    public function test_parses_agenda_html()
    {
        $html = '<ul class="menu"><li class="NAT"><a href="#">
Liga de Naciones de la UEFA: Francia vs Bélgica
<span class="t">19:45</span></a>
<ul>
<li class="subitem1"><a href="/eventos.html?r='.base64_encode('https://x.test/a?stream=espn').'" target="_top">ESPN<span>Calidad 720p</span></a></li>
<li class="subitem1"><a href="/eventos.html?r=%%%" target="_top">Roto<span>Calidad 720p</span></a></li>
</ul></li></ul>';

        $events = (new PelotaLibreScraper)->parseAgenda($html);

        $this->assertCount(1, $events);
        $e = $events->first();
        $this->assertSame('Liga de Naciones de la UEFA', $e['league']);
        $this->assertSame(['Francia', 'Bélgica'], [$e['home'], $e['away']]);
        $this->assertSame('15:45', $e['time']); // UTC+1 → AR
        $this->assertSame([[
            'source' => 'ESPN',
            'quality' => '720p',
            'url' => 'https://x.test/a?stream=espn',
            'embed' => 'https://x.test/a?stream=espn',
        ]], $e['options']);
    }
}
