<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentAcknowledgement;
use App\Models\DocumentVersion;
use App\Models\HospitalDocument;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DocumentPlatformController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ── Documents CRUD ────────────────────────────────────────────

    public function listDocuments(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = HospitalDocument::where('tenant_id', $ctx->tenantId());

        if ($cat = $request->query('category')) $query->where('category', $cat);
        if ($type = $request->query('document_type')) $query->where('document_type', $type);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($dept = $request->query('department')) $query->where('department', $dept);
        if ($pid = $request->query('patient_id')) $query->where('patient_id', $pid);
        if ($sid = $request->query('staff_id')) $query->where('staff_id', $sid);
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('document_code', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        $docs = $query->orderByDesc('created_at')->paginate(25);

        return Envelope::success(data: $docs, request: $request);
    }

    public function showDocument(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: false);
        $document->load('versions', 'acknowledgements');
        return Envelope::success(data: $document, request: $request);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => 'required|string|max:100',
            'category' => 'required|string|in:clinical,financial,hr,administrative,governance,procurement,patient_generated',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'patient_id' => 'nullable|uuid',
            'encounter_id' => 'nullable|uuid',
            'staff_id' => 'nullable|uuid',
            'department' => 'nullable|string',
            'classification' => 'sometimes|string|in:public,internal,clinical,financial,hr,restricted,confidential',
            'file_path' => 'nullable|string',
            'mime_type' => 'nullable|string',
            'file_size_bytes' => 'nullable|integer',
            'object_key' => 'nullable|string',
            'metadata' => 'nullable|array',
            'tags' => 'nullable|array',
            'retention_days' => 'nullable|integer',
        ]);

        $ctx = TenantContext::current();
        $doc = HospitalDocument::create([
            'tenant_id' => $ctx->tenantId(),
            'facility_id' => $ctx->facilityId(),
            'document_code' => 'DOC-' . strtoupper(Str::random(8)),
            'document_type' => $data['document_type'],
            'category' => $data['category'],
            'classification' => $data['classification'] ?? 'internal',
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'patient_id' => $data['patient_id'] ?? null,
            'encounter_id' => $data['encounter_id'] ?? null,
            'staff_id' => $data['staff_id'] ?? null,
            'department' => $data['department'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'file_size_bytes' => $data['file_size_bytes'] ?? null,
            'object_key' => $data['object_key'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'tags' => $data['tags'] ?? null,
            'retention_days' => $data['retention_days'] ?? null,
            'status' => 'draft',
            'version' => 1,
            'is_latest' => true,
            'uploaded_by' => $ctx->user?->getKey(),
        ]);

        $this->audit->record('document.created', 'hospital_document', $doc->getKey(), [
            'documentCode' => $doc->document_code,
            'category' => $doc->category,
            'type' => $doc->document_type,
        ], $request);

        return Envelope::success(data: $doc, status: 201, request: $request);
    }

    public function updateDocument(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'classification' => 'sometimes|string|in:public,internal,clinical,financial,hr,restricted,confidential',
            'tags' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);
        $document->update($data);
        $this->audit->record('document.updated', 'hospital_document', $document->getKey(), ['changes' => array_keys($data)], $request);
        return Envelope::success(data: $document, request: $request);
    }

    public function finalizeDocument(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);
        $ctx = TenantContext::current();
        $document->update([
            'status' => 'final',
            'finalized_by' => $ctx->user?->getKey(),
            'finalized_at' => now(),
        ]);
        $this->audit->record('document.finalized', 'hospital_document', $document->getKey(), [], $request);
        return Envelope::success(data: $document, request: $request);
    }

    public function archiveDocument(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);
        $document->update(['status' => 'archived']);
        $this->audit->record('document.archived', 'hospital_document', $document->getKey(), [], $request);
        return Envelope::success(data: $document, request: $request);
    }

    // ── Document Versions ────────────────────────────────────────

    public function listVersions(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: false);
        $versions = DocumentVersion::where('document_id', $document->getKey())
            ->orderByDesc('version_number')->get();
        return Envelope::success(data: $versions, request: $request);
    }

    public function createVersion(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file_path' => 'nullable|string',
            'mime_type' => 'nullable|string',
            'file_size_bytes' => 'nullable|integer',
            'object_key' => 'nullable|string',
            'change_reason' => 'nullable|string',
        ]);

        $ctx = TenantContext::current();
        $nextVersion = $document->version + 1;

        DocumentVersion::create([
            'tenant_id' => $ctx->tenantId(),
            'document_id' => $document->getKey(),
            'version_number' => $document->version,
            'title' => $document->title,
            'file_path' => $document->file_path,
            'file_hash' => $document->file_hash,
            'mime_type' => $document->mime_type,
            'file_size_bytes' => $document->file_size_bytes,
            'object_key' => $document->object_key,
            'change_reason' => 'Previous version before update',
            'created_by' => $ctx->user?->getKey(),
        ]);

        $document->update([
            'version' => $nextVersion,
            'title' => $data['title'],
            'description' => $data['description'] ?? $document->description,
            'file_path' => $data['file_path'] ?? $document->file_path,
            'mime_type' => $data['mime_type'] ?? $document->mime_type,
            'file_size_bytes' => $data['file_size_bytes'] ?? $document->file_size_bytes,
            'object_key' => $data['object_key'] ?? $document->object_key,
            'status' => 'draft',
        ]);

        $this->audit->record('document.version_created', 'hospital_document', $document->getKey(), [
            'version' => $nextVersion,
            'changeReason' => $data['change_reason'] ?? null,
        ], $request);

        return Envelope::success(data: $document, request: $request);
    }

    // ── Acknowledgements ─────────────────────────────────────────

    public function requestAcknowledgement(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: true);
        $data = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'uuid',
        ]);

        $ctx = TenantContext::current();
        $created = [];
        foreach ($data['user_ids'] as $userId) {
            $ack = DocumentAcknowledgement::firstOrCreate(
                ['document_id' => $document->getKey(), 'user_id' => $userId],
                ['tenant_id' => $ctx->tenantId(), 'status' => 'pending']
            );
            $created[] = $ack;
        }

        $this->audit->record('document.acknowledgement_requested', 'hospital_document', $document->getKey(), [
            'userCount' => count($created),
        ], $request);

        return Envelope::success(data: ['requested' => count($created)], status: 201, request: $request);
    }

    public function acknowledge(Request $request, DocumentAcknowledgement $ack): JsonResponse
    {
        $ack->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->audit->record('document.acknowledged', 'document_acknowledgement', $ack->getKey(), [
            'documentId' => $ack->document_id,
        ], $request);

        return Envelope::success(data: $ack, request: $request);
    }

    public function listAcknowledgements(Request $request, HospitalDocument $document): JsonResponse
    {
        AccessCheck::scoped($document, write: false);
        $acks = DocumentAcknowledgement::where('document_id', $document->getKey())->get();
        return Envelope::success(data: $acks, request: $request);
    }

    // ── Document Platform Dashboard ───────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $tid = $ctx->tenantId();

        $data = [
            'totalDocuments' => HospitalDocument::where('tenant_id', $tid)->count(),
            'draftDocuments' => HospitalDocument::where('tenant_id', $tid)->where('status', 'draft')->count(),
            'finalDocuments' => HospitalDocument::where('tenant_id', $tid)->where('status', 'final')->count(),
            'pendingAcknowledgements' => DocumentAcknowledgement::where('tenant_id', $tid)
                ->where('status', 'pending')->count(),
            'byCategory' => HospitalDocument::where('tenant_id', $tid)
                ->selectRaw('category, count(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category'),
        ];

        return Envelope::success(data: $data, request: $request);
    }
}
