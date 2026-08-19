<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AudienceSegment;
use App\Models\BroadcastCampaign;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification & Broadcast Campaign API (Phase 12).
 *
 * Manages notification templates, audience segments, broadcast campaigns,
 * delivery tracking, and emergency broadcasts.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $service,
    ) {}

    // ── Templates ──

    public function indexTemplates(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $templates = NotificationTemplate::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($templates);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'code' => 'required|string|max:100|unique:notification_templates,code',
            'name' => 'required|string|max:255',
            'channel' => 'required|string|in:in_app,email,sms,push',
            'type' => 'required|string|in:appointment_reminder,result,billing,clinical_alert,stock_alert,emergency,broadcast',
            'subject' => 'nullable|string|max:255',
            'body_template' => 'required|string',
            'locale' => 'string|max:10',
            'metadata' => 'array',
        ]);

        $template = NotificationTemplate::create([
            'tenant_id' => $tenantId,
            ...$validated,
        ]);

        return response()->json($template, 201);
    }

    // ── Audience Segments ──

    public function indexSegments(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $segments = AudienceSegment::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($segments);
    }

    public function storeSegment(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'code' => 'required|string|max:100|unique:audience_segments,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'scope_type' => 'required|string|in:national,organization,facility,department,role,custom',
            'criteria' => 'required|array',
        ]);

        $segment = AudienceSegment::create([
            'tenant_id' => $tenantId,
            ...$validated,
        ]);

        return response()->json($segment, 201);
    }

    // ── Broadcast Campaigns ──

    public function indexCampaigns(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $status = $request->query('status');
        $isEmergency = $request->query('emergency');

        $query = BroadcastCampaign::where('tenant_id', $tenantId)
            ->with(['template', 'segment'])
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($isEmergency !== null) {
            $query->where('is_emergency', $isEmergency === 'true');
        }

        return response()->json($query->paginate(25));
    }

    public function showCampaign(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $campaign = BroadcastCampaign::where('tenant_id', $tenantId)
            ->with(['template', 'segment', 'deliveryAttempts', 'recipients'])
            ->findOrFail($id);

        return response()->json($campaign);
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->attributes->get('user_id');

        $validated = $request->validate([
            'code' => 'required|string|max:100|unique:broadcast_campaigns,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'string|in:low,normal,high,urgent,emergency',
            'severity' => 'string|in:info,warning,critical,emergency',
            'is_emergency' => 'boolean',
            'template_id' => 'nullable|uuid|exists:notification_templates,id',
            'segment_id' => 'nullable|uuid|exists:audience_segments,id',
            'message_content' => 'required|array',
            'targeting_criteria' => 'array',
            'delivery_config' => 'array',
            'scheduled_at' => 'nullable|date|after:now',
            'approval_required' => 'string|in:none,facility_admin,org_admin,national_admin',
            'acknowledgement_required' => 'boolean',
            'escalation_policy' => 'array',
            'retry_policy' => 'array',
        ]);

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => BroadcastCampaign::STATUS_DRAFT,
            'approval_required' => 'facility_admin',
            ...$validated,
        ]);

        return response()->json($campaign, 201);
    }

    public function transitionCampaign(Request $request, string $id, string $action): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->attributes->get('user_id');

        $campaign = BroadcastCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        $statusMap = [
            'submit' => BroadcastCampaign::STATUS_REVIEW,
            'approve' => BroadcastCampaign::STATUS_APPROVED,
            'schedule' => BroadcastCampaign::STATUS_SCHEDULED,
            'dispatch' => null, // Special handling
            'cancel' => BroadcastCampaign::STATUS_CANCELLED,
        ];

        if (! isset($statusMap[$action])) {
            return response()->json(['error' => 'Invalid action'], 422);
        }

        if ($action === 'cancel') {
            $this->service->cancelCampaign($campaign, $request->input('reason', 'Cancelled by user'));

            return response()->json($campaign);
        }

        if ($action === 'approve') {
            $this->service->approveCampaign($campaign, $userId);

            return response()->json($campaign);
        }

        if ($action === 'dispatch') {
            DB::transaction(function () use ($campaign) {
                $this->service->dispatchCampaign($campaign);
            });

            return response()->json($campaign->fresh());
        }

        $newStatus = $statusMap[$action];
        if (! $campaign->canTransitionTo($newStatus)) {
            return response()->json(['error' => "Cannot transition from {$campaign->status} to {$newStatus}"], 422);
        }

        $campaign->update(['status' => $newStatus]);

        return response()->json($campaign);
    }

    // ── Delivery Tracking ──

    public function campaignDelivery(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $campaign = BroadcastCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        $attempts = $campaign->deliveryAttempts()
            ->with('notification')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json([
            'campaign' => $campaign,
            'deliveries' => $attempts,
            'summary' => [
                'total' => $campaign->total_recipients,
                'delivered' => $campaign->delivered_count,
                'failed' => $campaign->failed_count,
                'acknowledged' => $campaign->acknowledged_count,
            ],
        ]);
    }

    public function acknowledgeDelivery(Request $request, string $attemptId): JsonResponse
    {
        $attempt = DeliveryAttempt::findOrFail($attemptId);

        $this->service->acknowledge($attempt, $request->input('data'));

        return response()->json(['status' => 'acknowledged']);
    }

    // ── Emergency Broadcast ──

    public function emergencyBroadcast(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->attributes->get('user_id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'message_content' => 'required|array',
            'channels' => 'required|array|min:1',
            'channels.*' => 'string|in:in_app,email,sms,push',
            'targeting_criteria' => 'required|array',
            'escalation_policy' => 'array',
        ]);

        // Emergency broadcasts bypass approval
        $campaign = BroadcastCampaign::create([
            'tenant_id' => $tenantId,
            'code' => 'EMRG-'.strtoupper(uniqid()),
            'name' => $validated['name'],
            'status' => BroadcastCampaign::STATUS_APPROVED,
            'priority' => BroadcastCampaign::PRIORITY_EMERGENCY,
            'severity' => BroadcastCampaign::SEVERITY_EMERGENCY,
            'is_emergency' => true,
            'message_content' => $validated['message_content'],
            'targeting_criteria' => $validated['targeting_criteria'],
            'delivery_config' => ['channels' => $validated['channels']],
            'escalation_policy' => $validated['escalation_policy'] ?? [],
            'approval_required' => 'none',
            'acknowledgement_required' => true,
            'created_by' => $userId,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        // Dispatch immediately
        DB::transaction(function () use ($campaign) {
            $this->service->dispatchCampaign($campaign);
        });

        return response()->json($campaign->fresh(), 201);
    }

    // ── Dashboard / Stats ──

    public function stats(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $active = BroadcastCampaign::where('tenant_id', $tenantId)
            ->whereIn('status', ['sending', 'scheduled'])
            ->count();

        $emergency = BroadcastCampaign::where('tenant_id', $tenantId)
            ->where('is_emergency', true)
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->count();

        $recentSent = BroadcastCampaign::where('tenant_id', $tenantId)
            ->where('status', 'sent')
            ->where('completed_at', '>=', now()->subDays(7))
            ->count();

        $totalDelivered = Notification::where('tenant_id', $tenantId)
            ->where('status', 'sent')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return response()->json([
            'active_campaigns' => $active,
            'active_emergencies' => $emergency,
            'recent_sent' => $recentSent,
            'total_delivered_30d' => $totalDelivered,
        ]);
    }
}
