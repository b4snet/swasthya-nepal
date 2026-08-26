<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Services\DocumentCenterService;
use App\Services\PdfGenerator;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centralized Document Center (Phase 84): browse, generate, verify,
 * share, and download documents across all hospital workflows.
 */
final class DocumentCenterController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentCenterService $docService,
        private readonly PdfGenerator $pdf,
    ) {}

    /**
     * GET /organizations/{org}/documents — list documents with filters.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = GeneratedDocument::query()
            ->where('tenant_id', $organization->getKey())
            ->orderByDesc('created_at');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        if ($request->filled('category')) {
            $query->where('category', $request->validated('category'));
        }
        if ($request->filled('documentType')) {
            $query->where('document_type', $request->validated('documentType'));
        }
        if ($request->filled('patientId')) {
            $query->where('patient_id', $request->validated('patientId'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('document_number', 'ilike', "%{$search}%")
                    ->orWhere('content_text', 'ilike', "%{$search}%");
            });
        }

        $documents = $query->paginate(50);

        return Envelope::success(data: [
            'data' => $documents->getCollection()->map(fn (GeneratedDocument $d): array => $d->present())->values(),
            'total' => $documents->total(),
            'page' => $documents->currentPage(),
            'lastPage' => $documents->lastPage(),
        ], request: $request);
    }

    /**
     * GET /documents/platform — platform-level list across all tenants.
     */
    public function platformIndex(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $query = GeneratedDocument::query()->orderByDesc('created_at');

        // Platform sees everything; tenant-scoped users see their tenant only.
        if (! $context->isPlatform && $context->tenantId() !== null) {
            $query->where('tenant_id', $context->tenantId());
        }

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        if ($request->filled('category')) {
            $query->where('category', $request->validated('category'));
        }
        if ($request->filled('documentType')) {
            $query->where('document_type', $request->validated('documentType'));
        }
        if ($request->filled('patientId')) {
            $query->where('patient_id', $request->validated('patientId'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('document_number', 'ilike', "%{$search}%")
                    ->orWhere('content_text', 'ilike', "%{$search}%");
            });
        }

        $documents = $query->paginate(50);

        return Envelope::success(data: [
            'data' => $documents->getCollection()->map(fn (GeneratedDocument $d): array => $d->present())->values(),
            'total' => $documents->total(),
            'page' => $documents->currentPage(),
            'lastPage' => $documents->lastPage(),
        ], request: $request);
    }

    /**
     * GET /documents/{document} — show document with content.
     */
    public function show(Request $request, GeneratedDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: false);

        $document->load('patient:id,full_name,mrn');

        $data = $document->present();
        $data['contentHtml'] = $document->content_html;
        $data['brandingSnapshot'] = $document->branding_snapshot;
        $data['metadata'] = $document->metadata;

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * POST /organizations/{org}/documents/generate — generate a new document.
     */
    public function generate(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $validated = $request->validate([
            'documentType' => ['required', 'string', 'in:lab_report,radiology_report,discharge_summary,invoice,receipt,prescription,referral,consent,form,clinical_note,other'],
            'category' => ['required', 'string', 'in:clinical,financial,administrative,operational,compliance'],
            'title' => ['required', 'string', 'max:255'],
            'contentHtml' => ['required', 'string', 'max:50000'],
            'contentText' => ['nullable', 'string', 'max:50000'],
            'patientId' => ['nullable', 'uuid'],
            'providerStaffId' => ['nullable', 'uuid'],
            'providerName' => ['nullable', 'string', 'max:255'],
            'departmentName' => ['nullable', 'string', 'max:255'],
            'sourceType' => ['nullable', 'string', 'max:100'],
            'sourceId' => ['nullable', 'uuid'],
            'metadata' => ['nullable', 'array'],
            'visibility' => ['nullable', 'string', 'in:staff,patient,both'],
        ]);

        $context = TenantContext::current();
        $facilityId = $context->facilityId() ?? '';

        $document = $this->docService->generate([
            'tenantId' => (string) $context->tenantId(),
            'facilityId' => (string) $facilityId,
            'documentType' => $validated['documentType'],
            'category' => $validated['category'],
            'title' => $validated['title'],
            'contentHtml' => $validated['contentHtml'],
            'contentText' => $validated['contentText'] ?? null,
            'patientId' => $validated['patientId'] ?? null,
            'providerStaffId' => $validated['providerStaffId'] ?? null,
            'providerName' => $validated['providerName'] ?? null,
            'departmentName' => $validated['departmentName'] ?? null,
            'sourceType' => $validated['sourceType'] ?? null,
            'sourceId' => $validated['sourceId'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'visibility' => $validated['visibility'] ?? 'staff',
        ]);

        $this->audit->record(
            'document.generated',
            'generated_documents',
            $document->getKey(),
            ['documentNumber' => $document->document_number, 'type' => $document->document_type, 'category' => $document->category],
            $request,
        );

        return Envelope::success(data: $document->present(), status: 201, request: $request);
    }

    /**
     * POST /documents/{document}/verify — mark document as verified.
     */
    public function verify(Request $request, GeneratedDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);

        $context = TenantContext::current();

        $document->update([
            'verified' => true,
            'verified_by_staff_id' => $context->user?->getKey(),
            'verified_at' => now(),
            'status' => GeneratedDocument::STATUS_VERIFIED,
        ]);

        $this->audit->record(
            'document.verified',
            'generated_documents',
            $document->getKey(),
            ['documentNumber' => $document->document_number],
            $request,
        );

        return Envelope::success(data: $document->fresh()->present(), request: $request);
    }

    /**
     * POST /documents/{document}/sign — mark document as signed.
     */
    public function sign(Request $request, GeneratedDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);

        $context = TenantContext::current();

        $document->update([
            'signed' => true,
            'signed_by_staff_id' => $context->user?->getKey(),
            'signed_at' => now(),
            'status' => GeneratedDocument::STATUS_FINAL,
        ]);

        $this->audit->record(
            'document.signed',
            'generated_documents',
            $document->getKey(),
            ['documentNumber' => $document->document_number],
            $request,
        );

        return Envelope::success(data: $document->fresh()->present(), request: $request);
    }

    /**
     * POST /documents/{document}/share — share document with patient.
     */
    public function share(Request $request, GeneratedDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);

        $document->update([
            'shared_with_patient' => true,
            'shared_at' => now(),
        ]);

        $this->audit->record(
            'document.shared',
            'generated_documents',
            $document->getKey(),
            ['documentNumber' => $document->document_number, 'patientId' => $document->patient_id],
            $request,
        );

        return Envelope::success(data: $document->fresh()->present(), request: $request);
    }

    /**
     * GET /documents/categories — list document types and categories.
     */
    public function categories(): JsonResponse
    {
        return Envelope::success(data: [
            'types' => GeneratedDocument::types(),
            'categories' => GeneratedDocument::categories(),
        ]);
    }

    /**
     * GET /documents/stats — document center statistics.
     */
    public function stats(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = GeneratedDocument::query()->where('tenant_id', $organization->getKey());

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $total = (clone $query)->count();
        $byCategory = (clone $query)->selectRaw('category, count(*) as count')->groupBy('category')->pluck('count', 'category');
        $byType = (clone $query)->selectRaw('document_type, count(*) as count')->groupBy('document_type')->pluck('count', 'document_type');
        $verified = (clone $query)->where('verified', true)->count();
        $signed = (clone $query)->where('signed', true)->count();
        $shared = (clone $query)->where('shared_with_patient', true)->count();
        $recent = (clone $query)->where('created_at', '>=', now()->subDays(7))->count();

        return Envelope::success(data: [
            'total' => $total,
            'verified' => $verified,
            'signed' => $signed,
            'sharedWithPatient' => $shared,
            'recent7Days' => $recent,
            'byCategory' => $byCategory,
            'byType' => $byType,
        ], request: $request);
    }

    /**
     * GET /documents/{document}/pdf — download the document as PDF.
     *
     * If the PDF already exists on disk, stream it directly.
     * Otherwise, generate it on-the-fly from the stored HTML.
     */
    public function downloadPdf(Request $request, GeneratedDocument $document)
    {
        AccessCheck::scoped($document, write: false);

        // If PDF exists, stream it
        if ($document->pdf_path && $this->pdf->exists($document->pdf_path)) {
            $this->audit->record(
                'document.pdf.downloaded',
                'generated_documents',
                $document->getKey(),
                ['documentNumber' => $document->document_number],
                $request,
            );

            return response()->file(
                storage_path('app/'.$document->pdf_path),
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="'.$document->document_number.'.pdf"',
                ],
            );
        }

        // Generate on-the-fly if HTML exists
        if (! $document->content_html) {
            return response()->json(['message' => 'Document has no content to convert to PDF'], 404);
        }

        $result = $this->pdf->generate(
            $document->content_html,
            $document->getKey(),
            $document->tenant_id,
        );

        $document->update([
            'pdf_path' => $result['path'],
            'page_count' => $result['pageCount'],
        ]);

        $this->audit->record(
            'document.pdf.generated',
            'generated_documents',
            $document->getKey(),
            ['documentNumber' => $document->document_number, 'pageCount' => $result['pageCount']],
            $request,
        );

        return response()->file(
            storage_path('app/'.$result['path']),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$document->document_number.'.pdf"',
            ],
        );
    }

    /**
     * POST /documents/{document}/pdf — force-regenerate the PDF.
     */
    public function regeneratePdf(Request $request, GeneratedDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);

        if (! $document->content_html) {
            return response()->json(['message' => 'Document has no content to convert to PDF'], 422);
        }

        $result = $this->pdf->generate(
            $document->content_html,
            $document->getKey(),
            $document->tenant_id,
        );

        $document->update([
            'pdf_path' => $result['path'],
            'page_count' => $result['pageCount'],
        ]);

        $this->audit->record(
            'document.pdf.regenerated',
            'generated_documents',
            $document->getKey(),
            ['documentNumber' => $document->document_number, 'pageCount' => $result['pageCount']],
            $request,
        );

        return Envelope::success(data: [
            'pdfPath' => $result['path'],
            'pageCount' => $result['pageCount'],
            'sizeBytes' => $result['sizeBytes'],
        ], request: $request);
    }
}
