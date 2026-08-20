<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComplianceReport;
use App\Models\ReportLineageEntry;
use App\Models\ReportRun;
use App\Models\ReportSubscription;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateVersion;
use App\Services\ComplianceService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 18 — National Analytics Reporting & Compliance.
 *
 * Compliance reports, acknowledgments, subscriptions, lineage, exports,
 * and report template versioning. All operations are tenant/facility
 * isolated and audited.
 */
final class ComplianceController extends Controller
{
    public function __construct(
        private readonly ComplianceService $compliance,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /analytics/compliance-reports */
    public function indexComplianceReports(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $query = ComplianceReport::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('generated_at');

        if ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $reports = $query->paginate(25);

        return Envelope::success(data: $reports->through(fn ($r) => $this->presentComplianceReport($r)), request: $request);
    }

    /** POST /analytics/compliance-reports */
    public function storeComplianceReport(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $validated = $request->validate([
            'reportCode' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:privacy,security,clinical_quality,financial_controls,operational_governance',
            'scope' => 'required|string|in:facility,organization,national',
            'acknowledgmentRoles' => 'nullable|array',
        ]);

        $staffId = $this->currentStaffId($context);

        $report = $this->compliance->createComplianceReport(
            (string) $context->tenantId(),
            $context->facilityId(),
            $validated['reportCode'],
            $validated['title'],
            $validated['category'],
            $validated['scope'],
            $validated['acknowledgmentRoles'] ?? [],
            $staffId,
        );

        $this->audit->record('compliance.report_created', 'compliance_report', $report->getKey(), [
            'code' => $report->report_code,
            'category' => $report->category,
        ], $request);

        return Envelope::success(data: $this->presentComplianceReport($report), status: 201, request: $request);
    }

    /** GET /compliance-reports/{report} */
    public function showComplianceReport(ComplianceReport $report, Request $request): JsonResponse
    {
        AccessCheck::scoped($report, write: false);
        $report->load(['items', 'acknowledgments.staff']);

        return Envelope::success(data: $this->presentComplianceReport($report), request: $request);
    }

    /** POST /compliance-reports/{report}/items */
    public function storeItem(Request $request, ComplianceReport $report): JsonResponse
    {
        AccessCheck::scoped($report, write: true);

        $validated = $request->validate([
            'ruleCode' => 'required|string|max:100',
            'ruleName' => 'required|string|max:255',
            'severity' => 'required|string|in:critical,high,medium,low,info',
            'status' => 'required|string|in:pass,fail,warning,na',
            'description' => 'required|string',
            'evidence' => 'nullable|array',
            'recommendations' => 'nullable|array',
        ]);

        $item = $this->compliance->addItem(
            $report,
            $validated['ruleCode'],
            $validated['ruleName'],
            $validated['severity'],
            $validated['status'],
            $validated['description'],
            $validated['evidence'] ?? [],
            $validated['recommendations'] ?? [],
        );

        return Envelope::success(data: [
            'id' => $item->getKey(),
            'ruleCode' => $item->rule_code,
            'status' => $item->status,
        ], status: 201, request: $request);
    }

    /** POST /compliance-reports/{report}/publish */
    public function publish(ComplianceReport $report, Request $request): JsonResponse
    {
        AccessCheck::scoped($report, write: true);

        $report = $this->compliance->publishReport($report);

        $this->audit->record('compliance.report_published', 'compliance_report', $report->getKey(), [
            'code' => $report->report_code,
        ], $request);

        return Envelope::success(data: $this->presentComplianceReport($report), request: $request);
    }

    /** POST /compliance-reports/{report}/acknowledge */
    public function acknowledge(ComplianceReport $report, Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $staffId = $this->currentStaffId($context);

        $validated = $request->validate([
            'action' => 'nullable|string|in:acknowledged,exception_noted',
            'notes' => 'nullable|string',
        ]);

        $ack = $this->compliance->acknowledgeReport(
            $report,
            $staffId,
            $validated['action'] ?? 'acknowledged',
            $validated['notes'] ?? null,
        );

        $this->audit->record('compliance.report_acknowledged', 'compliance_report', $report->getKey(), [
            'staffId' => $staffId,
            'action' => $ack->action,
        ], $request);

        return Envelope::success(data: [
            'id' => $ack->getKey(),
            'action' => $ack->action,
            'acknowledgedAt' => $ack->acknowledged_at->toIso8601String(),
        ], request: $request);
    }

    /** GET /analytics/report-subscriptions */
    public function indexSubscriptions(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $staffId = $context->user?->staff()
            ->where('tenant_id', $context->tenantId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();

        $subs = ReportSubscription::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
            ->where('status', '!=', ReportSubscription::STATUS_CANCELLED)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->getKey(),
                'templateId' => $s->report_template_id,
                'templateCode' => $s->template?->code,
                'complianceReportCode' => $s->compliance_report_code,
                'frequency' => $s->frequency,
                'deliveryMethod' => $s->delivery_method,
                'status' => $s->status,
                'lastDeliveredAt' => $s->last_delivered_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $subs, request: $request);
    }

    /** POST /analytics/report-subscriptions */
    public function storeSubscription(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $staffId = $this->currentStaffId($context);

        $validated = $request->validate([
            'reportTemplateId' => 'nullable|uuid',
            'complianceReportCode' => 'nullable|string',
            'frequency' => 'required|string|in:daily,weekly,monthly,on_publish',
            'deliveryMethod' => 'nullable|string|in:in_app,email',
        ]);

        $sub = $this->compliance->subscribeToReport(
            (string) $context->tenantId(),
            $context->facilityId(),
            $staffId,
            $validated['reportTemplateId'] ?? null,
            $validated['complianceReportCode'] ?? null,
            $validated['frequency'],
            $validated['deliveryMethod'] ?? 'in_app',
        );

        return Envelope::success(data: [
            'id' => $sub->getKey(),
            'frequency' => $sub->frequency,
            'status' => $sub->status,
        ], status: 201, request: $request);
    }

    /** POST /report-subscriptions/{subscription}/cancel */
    public function cancelSubscription(ReportSubscription $subscription, Request $request): JsonResponse
    {
        AccessCheck::scoped($subscription, write: true);
        $this->compliance->cancelSubscription($subscription);

        return Envelope::success(data: ['status' => 'cancelled'], request: $request);
    }

    /** GET /analytics/lineage/{reportRun} */
    public function lineage(ReportRun $reportRun, Request $request): JsonResponse
    {
        AccessCheck::scoped($reportRun, write: false);

        $entries = ReportLineageEntry::query()
            ->where('report_run_id', $reportRun->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->getKey(),
                'sourceTable' => $e->source_table,
                'sourceId' => $e->source_id,
                'metricCode' => $e->metric_code,
                'snapshotContext' => $e->snapshot_context,
            ])
            ->values();

        return Envelope::success(data: $entries, request: $request);
    }

    /** GET /analytics/template-versions/{template} */
    public function templateVersions(ReportTemplate $template, Request $request): JsonResponse
    {
        AccessCheck::scoped($template, write: false);

        $versions = ReportTemplateVersion::query()
            ->where('template_id', $template->getKey())
            ->orderByDesc('version')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->getKey(),
                'version' => $v->version,
                'snapshot' => $v->snapshot,
                'changeReason' => $v->change_reason,
                'createdAt' => $v->created_at->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $versions, request: $request);
    }

    /** POST /analytics/export-compliance — export compliance report as CSV */
    public function exportComplianceReport(ComplianceReport $report, Request $request): JsonResponse
    {
        AccessCheck::scoped($report, write: false);

        $items = $report->items()
            ->orderBy('severity')
            ->get()
            ->map(fn ($item) => [
                'ruleCode' => $item->rule_code,
                'ruleName' => $item->rule_name,
                'severity' => $item->severity,
                'status' => $item->status,
                'description' => $item->description,
            ]);

        $this->audit->record('compliance.report_exported', 'compliance_report', $report->getKey(), [
            'itemCount' => $items->count(),
        ], $request);

        return Envelope::success(data: [
            'report' => $this->presentComplianceReport($report),
            'items' => $items,
            'format' => 'json',
        ], request: $request);
    }

    private function currentStaffId(TenantContext $context): ?string
    {
        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }

    /** @return array<string, mixed> */
    private function presentComplianceReport(ComplianceReport $report): array
    {
        return [
            'id' => $report->getKey(),
            'reportCode' => $report->report_code,
            'title' => $report->title,
            'category' => $report->category,
            'scope' => $report->scope,
            'status' => $report->status,
            'summary' => $report->summary,
            'version' => $report->version,
            'generatedAt' => $report->generated_at?->toIso8601String(),
            'publishedAt' => $report->published_at?->toIso8601String(),
            'acknowledgedAt' => $report->acknowledged_at?->toIso8601String(),
            'acknowledgmentsRequired' => $report->acknowledgments_required,
            'items' => $report->relationLoaded('items')
                ? $report->items->map(fn ($i) => [
                    'id' => $i->getKey(),
                    'ruleCode' => $i->rule_code,
                    'ruleName' => $i->rule_name,
                    'severity' => $i->severity,
                    'status' => $i->status,
                    'description' => $i->description,
                    'evidence' => $i->evidence,
                    'recommendations' => $i->recommendations,
                ])->values()
                : null,
            'acknowledgments' => $report->relationLoaded('acknowledgments')
                ? $report->acknowledgments->map(fn ($a) => [
                    'id' => $a->getKey(),
                    'action' => $a->action,
                    'notes' => $a->notes,
                    'acknowledgedAt' => $a->acknowledged_at->toIso8601String(),
                ])->values()
                : null,
        ];
    }
}
