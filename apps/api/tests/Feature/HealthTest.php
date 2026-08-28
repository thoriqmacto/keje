<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_ping_endpoint_returns_ok(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'name', 'time']);
    }

    public function test_root_returns_json_status(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }
}
