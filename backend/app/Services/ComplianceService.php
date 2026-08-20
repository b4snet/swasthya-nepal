<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\ComplianceReport;
use App\Models\ComplianceReportItem;
use App\Models\ReportAcknowledgment;
use App\Models\ReportSubscription;
use App\Models\ReportTemplateVersion;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 18 — National Analytics Reporting & Compliance.
 *
 * Compliance report lifecycle, item creation, acknowledgment tracking,
 * subscription management, and template versioning.
 */
final class ComplianceService
{
    public function createComplianceReport(
        string $tenantId,
        ?string $facilityId,
        string $code,
        string $title,
        string $category,
        string $scope,
        array $acknowledgmentRoles,
        ?string $staffId,
    ): ComplianceReport {
        $version = ComplianceReport::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('report_code', $code)
            ->max('version') ?? 0;

        return $this->guardUnique(fn () => ComplianceReport::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'report_code' => $code,
            'title' => $title,
            'category' => $category,
            'scope' => $scope,
            'status' => ComplianceReport::STATUS_DRAFT,
            'summary' => ['total_items' => 0, 'pass_count' => 0, 'fail_count' => 0],
            'generated_by_staff_id' => $staffId,
            'generated_at' => now(),
            'acknowledgments_required' => $acknowledgmentRoles,
            'version' => $version + 1,
        ]));
    }

    public function addItem(
        ComplianceReport $report,
        string $ruleCode,
        string $ruleName,
        string $severity,
        string $status,
        string $description,
        array $evidence = [],
        array $recommendations = [],
    ): ComplianceReportItem {
        $item = ComplianceReportItem::query()->create([
            'tenant_id' => $report->tenant_id,
            'facility_id' => $report->facility_id,
            'compliance_report_id' => $report->getKey(),
            'rule_code' => $ruleCode,
            'rule_name' => $ruleName,
            'severity' => $severity,
            'status' => $status,
            'description' => $description,
            'evidence' => $evidence,
            'recommendations' => $recommendations,
        ]);

        // Update summary counts
        $items = ComplianceReportItem::query()
            ->where('compliance_report_id', $report->getKey())
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->get()
            ->pluck('cnt', 'status')
            ->toArray();

        $report->update([
            'summary' => [
                'total_items' => array_sum($items),
                'pass_count' => $items['pass'] ?? 0,
                'fail_count' => $items['fail'] ?? 0,
                'warning_count' => $items['warning'] ?? 0,
                'na_count' => $items['na'] ?? 0,
            ],
        ]);

        return $item;
    }

    public function publishReport(ComplianceReport $report): ComplianceReport
    {
        if ($report->status !== ComplianceReport::STATUS_DRAFT) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only draft reports can be published.', 409);
        }

        $report->update([
            'status' => ComplianceReport::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return $report->fresh();
    }

    public function acknowledgeReport(
        ComplianceReport $report,
        ?string $staffId,
        string $action = 'acknowledged',
        ?string $notes = null,
    ): ReportAcknowledgment {
        if ($report->status !== ComplianceReport::STATUS_PUBLISHED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only published reports can be acknowledged.', 409);
        }

        $existing = ReportAcknowledgment::query()
            ->where('compliance_report_id', $report->getKey())
            ->where('staff_id', $staffId)
            ->first();

        if ($existing) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This report has already been acknowledged by this staff member.', 409);
        }

        $ack = ReportAcknowledgment::query()->create([
            'tenant_id' => $report->tenant_id,
            'facility_id' => $report->facility_id,
            'compliance_report_id' => $report->getKey(),
            'staff_id' => $staffId,
            'action' => $action,
            'notes' => $notes,
            'acknowledged_at' => now(),
        ]);

        // Check if all required acknowledgments are in
        $requiredRoles = $report->acknowledgments_required ?? [];
        if (empty($requiredRoles)) {
            $report->update(['status' => ComplianceReport::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()]);
        } else {
            $ackCount = ReportAcknowledgment::query()
                ->where('compliance_report_id', $report->getKey())
                ->count();
            if ($ackCount >= count($requiredRoles)) {
                $report->update(['status' => ComplianceReport::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()]);
            }
        }

        return $ack;
    }

    public function subscribeToReport(
        string $tenantId,
        ?string $facilityId,
        ?string $staffId,
        ?string $templateId,
        ?string $complianceCode,
        string $frequency,
        string $deliveryMethod,
    ): ReportSubscription {
        return ReportSubscription::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'staff_id' => $staffId,
            'report_template_id' => $templateId,
            'compliance_report_code' => $complianceCode,
            'frequency' => $frequency,
            'delivery_method' => $deliveryMethod,
            'status' => ReportSubscription::STATUS_ACTIVE,
        ]);
    }

    public function cancelSubscription(ReportSubscription $subscription): void
    {
        $subscription->update(['status' => ReportSubscription::STATUS_CANCELLED]);
    }

    public function versionTemplate(
        string $templateId,
        array $snapshot,
        ?string $changeReason,
        ?string $staffId,
    ): ReportTemplateVersion {
        $version = ReportTemplateVersion::query()
            ->where('template_id', $templateId)
            ->max('version') ?? 0;

        return ReportTemplateVersion::query()->create([
            'tenant_id' => auth()->user()?->staff()
                ->where('status', '!=', 'departed')
                ->first()?->tenant_id ?? '',
            'template_id' => $templateId,
            'version' => $version + 1,
            'snapshot' => $snapshot,
            'change_reason' => $changeReason,
            'created_by_staff_id' => $staffId,
            'created_at' => now(),
        ]);
    }

    private function guardUnique(callable $create)
    {
        try {
            return DB::transaction($create);
        } catch (QueryException $e) {
            $pdo = $e->getPrevious();
            if ($pdo instanceof \PDOException && str_starts_with((string) $pdo->getCode(), '23505')) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A conflicting compliance report already exists.', 409);
            }
            throw $e;
        }
    }
}
