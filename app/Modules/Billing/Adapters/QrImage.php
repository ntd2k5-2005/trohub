<?php

namespace App\Modules\Billing\Adapters;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class QrImage
{
    public static function validate(string $bytes): string
    {
        if (strlen($bytes) > config('vietqr.max_image_bytes')) {
            throw new RuntimeException('VIETQR_IMAGE_TOO_LARGE');
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && @getimagesizefromstring($bytes) !== false) {
            return $bytes;
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            throw new RuntimeException('VIETQR_UNSAFE_SVG');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('VIETQR_INVALID_IMAGE');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (! $root || $root->localName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            throw new RuntimeException('VIETQR_INVALID_SVG');
        }
        $nodes = $document->getElementsByTagName('*');
        if ($nodes->length > 20000) {
            throw new RuntimeException('VIETQR_IMAGE_TOO_COMPLEX');
        }
        $tags = ['svg', 'g', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'clipPath', 'image'];
        $attributes = ['id', 'role', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'opacity', 'fill-opacity', 'fill-rule', 'clip-path', 'clip-rule', 'transform', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'shape-rendering', 'preserveAspectRatio', 'href'];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== 'http://www.w3.org/2000/svg' || ! in_array($node->localName, $tags, true)) {
                throw new RuntimeException('VIETQR_UNSAFE_SVG');
            }
            foreach ($node->attributes as $attribute) {
                if (! in_array($attribute->localName, $attributes, true) || $attribute->namespaceURI && $attribute->namespaceURI !== 'http://www.w3.org/1999/xlink') {
                    throw new RuntimeException('VIETQR_UNSAFE_SVG');
                }
                $value = $attribute->value;
                if ($attribute->localName === 'href') {
                    if ($node->localName !== 'image' || ! preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\s]+)$#', $value, $matches)) {
                        throw new RuntimeException('VIETQR_UNSAFE_SVG');
                    }
                    $raster = base64_decode($matches[2], true);
                    if (! $raster || @getimagesizefromstring($raster) === false) {
                        throw new RuntimeException('VIETQR_UNSAFE_SVG');
                    }
                } elseif (preg_match('/javascript:|vbscript:|expression\s*\(|@import|url\s*\((?!#[A-Za-z0-9_-]+\))/i', $value)) {
                    throw new RuntimeException('VIETQR_UNSAFE_SVG');
                }
            }
        }

        // Export only the validated root, excluding XML processing instructions.
        return $document->saveXML($root);
    }

    public static function type(string $bytes): string
    {
        return str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/svg+xml';
    }
}
