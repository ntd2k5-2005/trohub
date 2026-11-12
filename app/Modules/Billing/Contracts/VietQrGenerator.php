<?php

namespace App\Modules\Billing\Contracts;

interface VietQrGenerator
{
    /** Return validated PNG/SVG bytes. Failure must not imply money received. */
    public function image(array $payee, int $amount, string $reference): string;
}
