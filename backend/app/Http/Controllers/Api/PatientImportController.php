<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CsvImport;
use App\Models\Organization;
use App\Services\PatientCsvImportService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient CSV import workflow (Phase 80): template download, CSV upload,
 * column mapping, preview/dry-run, and full import with failure reporting.
 */
final class PatientImportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /organizations/{org}/patients/import/template — download CSV template.
     */
    public function template(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $columns = PatientCsvImportService::templateColumns();

        $headerLine = implode(',', array_keys($columns));
        $commentLine = '# '.implode(' | ', array_map(fn (string $k, string $v) => $k.': '.$v, array_keys($columns), array_values($columns)));
        $sampleLine = '"Ram Bahadur Thapa","1985-03-15","male","O+","9841234567","ram@example.com","12-34-56789","","Kathmandu-11","Bagmati","Sita Thapa","9841234568","spouse"';

        $content = $commentLine."\n".$headerLine."\n".$sampleLine."\n";

        return Envelope::success(data: [
            'csv' => $content,
            'columns' => $columns,
            'fileName' => 'patient-import-template.csv',
        ], request: $request);
    }

    /**
     * POST /organizations/{org}/patients/import — upload CSV and create import record.
     */
    public function upload(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'facilityId' => ['nullable', 'uuid'],
        ]);

        $file = $request->file('file');
        $fileName = 'imports/patients-'.time().'-'.$file->getClientOriginalName();
        $file->storeAs('local', $fileName);

        $context = TenantContext::current();
        $facilityId = $request->validated('facilityId')
            ?? $context->facilityId()
            ?? FacilityScope::resolve(null, write: true)->getKey();

        $import = CsvImport::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facilityId,
            'entity_type' => 'patient',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $fileName,
            'status' => CsvImport::STATUS_PENDING,
            'field_mapping' => [],
            'imported_by' => $context->user?->getKey(),
        ]);

        // Parse CSV to get headers
        $parsed = PatientCsvImportService::parseCsv($fileName);

        $this->audit->record(
            'patient.import.uploaded',
            'csv_imports',
            $import->getKey(),
            ['fileName' => $file->getClientOriginalName(), 'totalRows' => $parsed['totalRows'], 'headers' => $parsed['headers']],
            $request,
        );

        return Envelope::success(data: [
            'importId' => $import->getKey(),
            'fileName' => $file->getClientOriginalName(),
            'headers' => $parsed['headers'],
            'totalRows' => $parsed['totalRows'],
        ], status: 201, request: $request);
    }

    /**
     * PUT /patient-imports/{import}/mapping — set the column-to-field mapping.
     */
    public function setMapping(Request $request, CsvImport $import): JsonResponse
    {
        AccessCheck::scoped($import, write: true);

        $request->validate([
            'fieldMapping' => ['required', 'array'],
            'fieldMapping.*' => ['required', 'string'],
        ]);

        $import->update(['field_mapping' => $request->validated('fieldMapping')]);

        $this->audit->record(
            'patient.import.mapping_set',
            'csv_imports',
            $import->getKey(),
            ['mapping' => $request->validated('fieldMapping')],
            $request,
        );

        return Envelope::success(data: ['importId' => $import->getKey(), 'mapping' => $import->field_mapping], request: $request);
    }

    /**
     * POST /patient-imports/{import}/preview — dry-run validation.
     */
    public function preview(Request $request, CsvImport $import): JsonResponse
    {
        AccessCheck::scoped($import, write: true);

        if (empty($import->field_mapping)) {
            return Envelope::error('VALIDATION_ERROR', 'Set field mapping before previewing.', 422, request: $request);
        }

        $context = TenantContext::current();
        $result = PatientCsvImportService::previewImport($import, (string) $context->tenantId());

        return Envelope::success(data: $result, request: $request);
    }

    /**
     * POST /patient-imports/{import}/import — execute the full import.
     */
    public function import(Request $request, CsvImport $import): JsonResponse
    {
        AccessCheck::scoped($import, write: true);

        if (empty($import->field_mapping)) {
            return Envelope::error('VALIDATION_ERROR', 'Set field mapping before importing.', 422, request: $request);
        }

        $context = TenantContext::current();
        $facilityId = $import->facility_id ?? (string) FacilityScope::resolve(null, write: true)->getKey();

        $result = PatientCsvImportService::runImport(
            $import,
            (string) $context->tenantId(),
            $facilityId,
        );

        $this->audit->record(
            'patient.import.completed',
            'csv_imports',
            $import->getKey(),
            ['success' => $result['success'], 'errors' => $result['errors']],
            $request,
        );

        return Envelope::success(data: [
            'importId' => $import->getKey(),
            'status' => $import->fresh()->status,
            'totalRows' => $import->fresh()->total_rows,
            'successRows' => $import->fresh()->success_rows,
            'errorRows' => $import->fresh()->error_rows,
            'errorDetails' => $result['errorDetails'],
        ], request: $request);
    }

    /**
     * GET /patient-imports/{import} — get import status/details.
     */
    public function show(Request $request, CsvImport $import): JsonResponse
    {
        AccessCheck::scoped($import, write: false);

        return Envelope::success(data: [
            'id' => $import->getKey(),
            'status' => $import->status,
            'fileName' => $import->file_name,
            'totalRows' => $import->total_rows,
            'successRows' => $import->success_rows,
            'errorRows' => $import->error_rows,
            'fieldMapping' => $import->field_mapping,
            'validationErrors' => $import->validation_errors,
            'importErrors' => $import->import_errors,
            'createdAt' => $import->created_at?->toIso8601String(),
            'startedAt' => $import->started_at?->toIso8601String(),
            'completedAt' => $import->completed_at?->toIso8601String(),
        ], request: $request);
    }

    /**
     * GET /organizations/{org}/patient-imports — list imports for the tenant.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $imports = CsvImport::query()
            ->where('tenant_id', $organization->getKey())
            ->where('entity_type', 'patient')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (CsvImport $import): array => [
                'id' => $import->getKey(),
                'status' => $import->status,
                'fileName' => $import->file_name,
                'totalRows' => $import->total_rows,
                'successRows' => $import->success_rows,
                'errorRows' => $import->error_rows,
                'createdAt' => $import->created_at?->toIso8601String(),
                'completedAt' => $import->completed_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $imports, request: $request);
    }
}
