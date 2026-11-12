<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_commands_resolve_services_without_external_databases(): void
    {
        $this->artisan('data:project', ['--limit' => 1])->assertSuccessful();
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->artisan('billing:remind')->assertSuccessful();
    }
}
