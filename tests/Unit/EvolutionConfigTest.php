<?php

namespace Tests\Unit;

use Tests\TestCase;

class EvolutionConfigTest extends TestCase
{
    public function test_evolution_config_loads_with_expected_structure(): void
    {
        $config = config('evolution');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('notifications', $config);
        $this->assertArrayHasKey('enabled', $config['notifications']);
        $this->assertArrayHasKey('email', $config);
        $this->assertArrayHasKey('enabled', $config['email']);
        $this->assertArrayHasKey('to', $config['email']);
        $this->assertArrayHasKey('whatsapp', $config);
        $this->assertArrayHasKey('enabled', $config['whatsapp']);
        $this->assertArrayHasKey('base_url', $config['whatsapp']);
        $this->assertArrayHasKey('instance_name', $config['whatsapp']);
        $this->assertArrayHasKey('destination_number', $config['whatsapp']);
        $this->assertArrayHasKey('timeout_seconds', $config['whatsapp']);
        $this->assertArrayHasKey('connect_timeout_seconds', $config['whatsapp']);
    }
}
