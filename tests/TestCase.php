<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Explicit synthetic initial-workspace capacity; never a production plan default.
        config(['business.limits.trial.workspaces'=>3,'business.limits.monthly.workspaces'=>3]);
        // A browser/dev server may run at the same time on Windows. Keep its
        // compiled Blade files separate from this process's test artifacts.
        $path = base_path('kho/test-views/'.getmypid());
        File::ensureDirectoryExists($path);
        config(['view.compiled' => $path]);
        $this->app->forgetInstance('blade.compiler');
    }
}
