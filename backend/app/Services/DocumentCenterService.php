<?php

namespace App\Services;

use App\Models\GeneratedDocument;
use App\Models\HospitalBranding;
use App\Models\Patient;
use App\Support\DocumentNumberService;

/**
 * Centralized document generation service (Phase 84): renders documents
 * with hospital branding, patient identifiers, provider information,
 * document numbers, and timestamps. Prepares content for print/PDF.
 */
final class DocumentCenterService
{
    public function __construct(
        private readonly DocumentNumberService $numbering,
    ) {}

    /**
     * Generate a document and register it in the document center.
     *
     * @param  array{tenantId: string, facilityId: string, documentType: string, category: string, title: string, contentHtml: string, contentText?: string, patientId?: string, providerStaffId?: string, providerName?: string, departmentName?: string, sourceType?: string, sourceId?: string, metadata?: array, visibility?: string}  $params
     */
    public function generate(array $params): GeneratedDocument
    {
        $tenantId = $params['tenantId'];
        $facilityId = $params['facilityId'];

        // Generate document number
        $numberType = $this->mapTypeToNumberType($params['documentType']);
        $documentNumber = $this->numbering->next($tenantId, $numberType);

        // Capture branding snapshot
        $branding = HospitalBranding::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->first();

        $brandingSnapshot = $branding?->present() ?? [
            'hospitalName' => null,
            'addressLine1' => null,
            'city' => null,
            'phone' => null,
            'currency' => 'NPR',
            'currencySymbol' => 'Rs.',
        ];

        // Render document with branding
        $renderedHtml = $this->renderWithBranding(
            $params['contentHtml'],
            $brandingSnapshot,
            $documentNumber,
            $params['title'],
            $params['category'],
        );

        return GeneratedDocument::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'document_number' => $documentNumber,
            'document_type' => $params['documentType'],
            'category' => $params['category'],
            'title' => $params['title'],
            'source_type' => $params['sourceType'] ?? null,
            'source_id' => $params['sourceId'] ?? null,
            'patient_id' => $params['patientId'] ?? null,
            'provider_staff_id' => $params['providerStaffId'] ?? null,
            'provider_name' => $params['providerName'] ?? null,
            'department_name' => $params['departmentName'] ?? null,
            'content_html' => $renderedHtml,
            'content_text' => $params['contentText'] ?? strip_tags($renderedHtml),
            'metadata' => $params['metadata'] ?? null,
            'branding_snapshot' => $brandingSnapshot,
            'status' => GeneratedDocument::STATUS_GENERATED,
            'printable' => true,
            'pdf_capable' => true,
            'visibility' => $params['visibility'] ?? 'staff',
        ]);
    }

    /**
     * Render HTML content with hospital branding header and footer.
     */
    private function renderWithBranding(
        string $contentHtml,
        array $branding,
        string $documentNumber,
        string $title,
        string $category,
    ): string {
        $hospitalName = $branding['hospitalName'] ?? 'Hospital';
        $address = trim(($branding['addressLine1'] ?? '').' '.($branding['city'] ?? ''));
        $phone = $branding['phone'] ?? '';
        $header = $branding['documentHeader'] ?? '';
        $footer = $branding['documentFooter'] ?? '';
        $now = now()->format('Y-m-d H:i');
        $primaryColor = $branding['primaryColor'] ?? '#0891b2';

        $safeHospitalName = htmlspecialchars($hospitalName, ENT_QUOTES, 'UTF-8');
        $safeAddress = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
        $safePhone = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeDocumentNumber = htmlspecialchars($documentNumber, ENT_QUOTES, 'UTF-8');
        $headerBlock = $header !== ''
            ? '<div class="hospital-info">'.htmlspecialchars($header, ENT_QUOTES, 'UTF-8').'</div>'
            : '';
        $footerBlock = $footer !== ''
            ? '<div>'.htmlspecialchars($footer, ENT_QUOTES, 'UTF-8').'</div>'
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; color: #0f172a; font-size: 14px; line-height: 1.5; }
  .doc-header { text-align: center; border-bottom: 2px solid {$primaryColor}; padding-bottom: 16px; margin-bottom: 24px; }
  .doc-header h1 { margin: 0; font-size: 18px; color: {$primaryColor}; }
  .doc-header .hospital-name { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
  .doc-header .hospital-info { font-size: 12px; color: #64748b; }
  .doc-header .doc-number { font-size: 11px; color: #94a3b8; margin-top: 8px; font-family: monospace; }
  .doc-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; padding: 8px 12px; background: #f8fafc; border-left: 3px solid {$primaryColor}; }
  .doc-content { margin-bottom: 24px; }
  .doc-footer { border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 11px; color: #94a3b8; text-align: center; }
  .doc-footer .generated { margin-top: 8px; }
  @media print { body { padding: 0; } .doc-header { page-break-after: avoid; } }
</style>
</head>
<body>
  <div class="doc-header">
    <div class="hospital-name">{$safeHospitalName}</div>
    <div class="hospital-info">{$safeAddress}</div>
    <div class="hospital-info">{$safePhone}</div>
    {$headerBlock}
    <div class="doc-number">{$safeDocumentNumber}</div>
  </div>
  <div class="doc-title">{$safeTitle}</div>
  <div class="doc-content">
    {$contentHtml}
  </div>
  <div class="doc-footer">
    {$footerBlock}
    <div class="generated">Generated: {$now} | Category: {$category}</div>
  </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Map document type to numbering type.
     */
    private function mapTypeToNumberType(string $documentType): string
    {
        return match ($documentType) {
            GeneratedDocument::TYPE_LAB_REPORT => 'lab_report',
            GeneratedDocument::TYPE_RADIOLOGY_REPORT => 'radiology_report',
            GeneratedDocument::TYPE_DISCHARGE_SUMMARY => 'discharge',
            GeneratedDocument::TYPE_INVOICE => 'invoice',
            GeneratedDocument::TYPE_RECEIPT => 'receipt',
            GeneratedDocument::TYPE_PRESCRIPTION => 'prescription',
            GeneratedDocument::TYPE_REFERRAL => 'referral',
            GeneratedDocument::TYPE_CONSENT => 'consent',
            GeneratedDocument::TYPE_FORM => 'form',
            default => 'form',
        };
    }
}
