<?php

namespace AppHttpControllersApi;

final class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly AuditLogger $audit,
    ) {}

    // Chart of Accounts
    public function indexAccounts(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $accounts = Account::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId(), fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('code')
            ->get();

        return Envelope::success(data: $accounts, request: $request);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $account = Account::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'type' => $request->validated('type'),
            'category' => $request->validated('category'),
            'parent_id' => $request->validated('parent_id'),
            'description' => $request->validated('description'),
            'is_cash_account' => $request->validated('is_cash_account', false),
            'is_bank_account' => $request->validated('is_bank_account', false),
            'status' => Account::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);
        $this->audit->record('account.created', 'account', $account->getKey(), ['code' => $account->code, 'type' => $account->type], $request);

        return Envelope::success(data: $account, status: 201, request: $request);
    }

    // Journal Entries
    public function indexJournals(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $entries = JournalEntry::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId(), fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->get();

        return Envelope::success(data: $entries, request: $request);
    }

    public function storeJournal(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $entry = $this->accounting->createJournalEntry(
            tenantId: (string) $context->tenantId(),
            facilityId: $context->facilityId(),
            entryDate: $request->validated('entry_date'),
            periodId: $request->validated('period_id'),
            description: $request->validated('description'),
            lines: $request->validated('lines'),
            sourceType: $request->validated('source_type'),
            sourceId: $request->validated('source_id'),
            reference: $request->validated('reference'),
            createdBy: $context->user?->getKey(),
        );
        $this->audit->record('journal.created', 'journal_entry', $entry->getKey(), ['entry_number' => $entry->entry_number], $request);

        return Envelope::success(data: $entry, status: 201, request: $request);
    }

    public function postJournal(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $context = TenantContext::current();
        $entry = $this->accounting->postEntry($journalEntry, (string) $context->user?->getKey());
        $this->audit->record('journal.posted', 'journal_entry', $entry->getKey(), ['entry_number' => $entry->entry_number], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    // Trial Balance
    public function trialBalance(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $periodId = $request->query('period_id');
        $balance = $this->accounting->trialBalance(
            tenantId: (string) $context->tenantId(),
            facilityId: $context->facilityId(),
            periodId: $periodId ?? '',
        );

        return Envelope::success(data: $balance, request: $request);
    }
}
