<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StoreDocumentRequest;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\PatientTimeline;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient document METADATA (DATABASE.md §3.38).
 *
 * Object storage does not exist yet, so registration is honest: the record
 * is `staged` with no object key and there is no download endpoint. The
 * record, its audit trail, and its timeline entry are real; the bytes will
 * be when the storage integration lands.
 */
final class PatientDocumentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PatientTimeline $timeline,
    ) {}

    public function index(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $documents = $patient->documents()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PatientDocument $document): array => self::present($document))
            ->values();

        return Envelope::success(data: $documents, request: $request);
    }

    public function store(StoreDocumentRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();

        $document = PatientDocument::query()->create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->getKey(),
            'document_type' => $request->validated('documentType'),
            'checksum' => $request->validated('checksum'),
            'size_bytes' => $request->validated('sizeBytes'),
            'mime_type' => $request->validated('mimeType'),
            'status' => PatientDocument::STATUS_STAGED,
            'uploaded_by' => $context->user?->getKey(),
            'uploaded_at' => now(),
            'expires_at' => $request->validated('expiresAt'),
            'retention_class' => $request->validated('retentionClass'),
        ]);

        $this->audit->record(
            'patient.document.added',
            'patient_document',
            $document->getKey(),
            ['patientId' => $patient->getKey(), 'documentType' => $document->document_type, 'status' => $document->status],
            $request,
        );
        $this->timeline->record($patient, 'patient.document_added', ['documentType' => $document->document_type], $request);

        return Envelope::success(data: self::present($document), status: 201, request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(PatientDocument $document): array
    {
        return [
            'id' => $document->getKey(),
            'patientId' => $document->patient_id,
            'documentType' => $document->document_type,
            'mimeType' => $document->mime_type,
            'sizeBytes' => $document->size_bytes,
            'checksum' => $document->checksum,
            'status' => $document->status,
            'uploadedAt' => $document->uploaded_at?->toIso8601String(),
            'expiresAt' => $document->expires_at?->toIso8601String(),
            'retentionClass' => $document->retention_class,
            // objectKey is deliberately absent: no object storage yet.
        ];
    }
}
