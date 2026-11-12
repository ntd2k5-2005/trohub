<?php

namespace Tests\Unit;

use App\Modules\Billing\Adapters\QrImage;
use RuntimeException;
use Tests\TestCase;

class QrImageTest extends TestCase
{
    public function test_svg_qr_is_validated_and_processing_instructions_are_discarded(): void
    {
        $safe = '<?xml version="1.0"?><?xml-stylesheet href="https://untrusted.invalid/remote.css"?><svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><path d="M0 0h10v10z" fill="#000"/><text x="0" y="50">Thông tin chuyển khoản</text></svg>';
        $image = QrImage::validate($safe);
        $this->assertStringNotContainsString('xml-stylesheet', $image);
        $this->assertSame('image/svg+xml', QrImage::type($image));
    }

    public function test_external_resources_and_active_svg_content_are_rejected(): void
    {
        foreach (['<script>alert(1)</script>', '<image href="https://untrusted.invalid/a.png"/>', '<path onload="alert(1)"/>', '<foreignObject/>', '<rect fill="url(https://untrusted.invalid/p)"/>'] as $unsafe) {
            try {
                QrImage::validate('<svg xmlns="http://www.w3.org/2000/svg">'.$unsafe.'</svg>');
                $this->fail('Unsafe SVG was accepted');
            } catch (RuntimeException $exception) {
                $this->assertSame('VIETQR_UNSAFE_SVG', $exception->getMessage());
            }
        }
    }
}
