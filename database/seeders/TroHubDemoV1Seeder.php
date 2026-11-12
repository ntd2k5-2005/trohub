<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoV1Import;
use Illuminate\Database\Seeder;

/** Explicit historical import; deliberately NOT called by DatabaseSeeder. */
final class TroHubDemoV1Seeder extends Seeder
{
    public function run(): void
    {
        (new DemoV1Import)->run();
    }
}
