<?php

namespace App\Modules\Subscription;

/** Backward-compatible entry point. Trial B never purges business data. */
final class TrialPurge
{
    public function run(): int
    {
        return 0;
    }
}
