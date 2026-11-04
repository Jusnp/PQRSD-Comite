<?php

namespace Tests\Unit;

use App\Support\ProxyConfianza;
use PHPUnit\Framework\TestCase;

class ProxyConfianzaTest extends TestCase
{
    public function test_un_comodin_no_autoriza_proxies(): void
    {
        $this->assertSame([], ProxyConfianza::proxies('*, **'));
        $this->assertSame(
            ['10.8.0.4', '192.168.56.0/24'],
            ProxyConfianza::proxies(' 10.8.0.4, *, 192.168.56.0/24 ')
        );
    }

    public function test_los_hosts_son_exactos_e_incluyen_el_loopback(): void
    {
        $hosts = ProxyConfianza::hosts(
            'pqrsd.ejemplo.org',
            'https://pqrsd.ejemplo.org',
            'https://publico.ejemplo.org'
        );

        $this->assertContains('^127\\.0\\.0\\.1$', $hosts);
        $this->assertContains('^localhost$', $hosts);
        $this->assertContains('^pqrsd\\.ejemplo\\.org$', $hosts);
        $this->assertContains('^publico\\.ejemplo\\.org$', $hosts);
        $this->assertNotContains('*', $hosts);
    }
}
