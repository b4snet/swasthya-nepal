<?php

namespace App\Services;

use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;

/**
 * Server-side PDF generation for hospital documents.
 *
 * Converts HTML content (already rendered with hospital branding by
 * DocumentCenterService) into a PDF file and stores it on disk.
 *
 * Uses Dompdf which handles CSS2.1 and limited CSS3. The HTML is
 * expected to already include inline styles from DocumentCenterService.
 */
final class PdfGenerator
{
    /**
     * Generate a PDF from HTML content and store it.
     *
     * @param  string  $html  Full HTML document (with <html>, <head>, <body>)
     * @param  string  $documentId  The GeneratedDocument UUID (used for file naming)
     * @param  string  $tenantId  Tenant UUID (used for storage path isolation)
     * @return array{path: string, sizeBytes: int, pageCount: int}
     */
    public function generate(string $html, string $documentId, string $tenantId): array
    {
        $dompdf = new Dompdf;

        $options = $dompdf->getOptions();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultMediaType', 'print');
        $options->set('defaultPaperSize', 'A4');
        $options->set('isPhpEnabled', false);
        $options->setDpi(150);
        $dompdf->setOptions($options);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $pdfContent = $dompdf->output();

        // Determine page count from the canvas
        $pageCount = $dompdf->getCanvas() ? $dompdf->getCanvas()->get_page_count() : 1;

        // Store in tenant-isolated path
        $path = "documents/{$tenantId}/{$documentId}.pdf";

        Storage::disk('local')->put($path, $pdfContent);

        return [
            'path' => $path,
            'sizeBytes' => strlen($pdfContent),
            'pageCount' => $pageCount,
        ];
    }

    /**
     * Retrieve PDF content from storage.
     *
     * @return string|null Raw PDF bytes, or null if not found
     */
    public function retrieve(string $path): ?string
    {
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->get($path);
    }

    /**
     * Check if a PDF exists at the given path.
     */
    public function exists(string $path): bool
    {
        return Storage::disk('local')->exists($path);
    }

    /**
     * Delete a PDF from storage.
     */
    public function delete(string $path): bool
    {
        if (! Storage::disk('local')->exists($path)) {
            return false;
        }

        return Storage::disk('local')->delete($path);
    }
}
