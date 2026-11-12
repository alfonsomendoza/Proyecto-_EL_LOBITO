<?php

namespace App\Helpers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Generación de QR 100% local mediante bacon/bacon-qr-code (SVG).
 *
 * No contiene ningún algoritmo propio ni patrón simulado: si la librería no
 * está instalada devuelve null y la vista muestra un aviso, en lugar de
 * dibujar algo que parezca un QR pero no sea escaneable.
 */
class QrCode
{
    public static function svgDataUri(string $text, int $size = 200): ?string
    {
        if (! class_exists(Writer::class) || ! class_exists(SvgImageBackEnd::class)) {
            return null;
        }

        try {
            $renderer = new ImageRenderer(
                new RendererStyle($size),
                new SvgImageBackEnd()
            );

            $svg = (new Writer($renderer))->writeString($text);

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
