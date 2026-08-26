<?php

namespace AppServices;

final class AccountingService
{
    public function createJournalEntry(
        string $tenantId,
        ?string $facilityId,
        string $entryDate,
        string $periodId,
        string $description,
        array $lines,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $reference = null,
        ?string $createdBy = null,
    ): JournalEntry {
        if (empty($lines)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 422);
        }
        $totalDebit = array_sum(array_column($lines, 'debit_minor'));
        $totalCredit = array_sum(array_column($lines, 'credit_minor'));
        if ($totalDebit !== $totalCredit) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 422);
        }

        return DB::transaction(function () use ($tenantId, $facilityId, $entryDate, $periodId, $description, $lines, $sourceType, $sourceId, $reference, $createdBy): JournalEntry {
            $entryNumber = $this->nextEntryNumber($tenantId);
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId, 'facility_id' => $facilityId,
                'entry_number' => $entryNumber, 'entry_date' => $entryDate,
                'period_id' => $periodId, 'source_type' => $sourceType,
                'source_id' => $sourceId, 'description' => $description,
                'reference' => $reference, 'status' => JournalEntry::STATUS_DRAFT,
                'lock_version' => 0, 'created_by' => $createdBy,
            ]);
            foreach ($lines as $line) {
                JournalLine::query()->create([
                    'tenant_id' => $tenantId, 'facility_id' => $facilityId,
                    'journal_entry_id' => $entry->getKey(),
                    'account_id' => $line['account_id'],
                    'debit_minor' => $line['debit_minor'] ?? 0,
                    'credit_minor' => $line['credit_minor'] ?? 0,
                    'description' => $line['description'] ?? null,
                    'created_by' => $createdBy,
                ]);
            }

            return $entry->load('lines');
        });
    }

    public function reviewEntry(JournalEntry $entry, string $actorId): JournalEntry
    {
        $affected = DB::transaction(fn () => JournalEntry::query()->whereKey($entry->getKey())->where('status', JournalEntry::STATUS_DRAFT)->where('lock_version', $entry->lock_version)->update(['status' => JournalEntry::STATUS_REVIEWED, 'lock_version' => $entry->lock_version + 1, 'updated_by' => $actorId]));
        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::LOCK_CONFLICT, 409);
        }

        return $entry->refresh();
    }

    public function postEntry(JournalEntry $entry, string $actorId): JournalEntry
    {
        $affected = DB::transaction(fn () => JournalEntry::query()->whereKey($entry->getKey())->where('status', JournalEntry::STATUS_REVIEWED)->where('lock_version', $entry->lock_version)->update(['status' => JournalEntry::STATUS_POSTED, 'posted_at' => now(), 'posted_by' => $actorId, 'lock_version' => $entry->lock_version + 1, 'updated_by' => $actorId]));
        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::LOCK_CONFLICT, 409);
        }

        return $entry->refresh();
    }

    public function reverseEntry(JournalEntry $entry, string $reason, string $actorId): JournalEntry
    {
        $entry->load('lines');
        $reversalLines = $entry->lines->map(fn (JournalLine $line): array => ['account_id' => $line->account_id, 'debit_minor' => $line->credit_minor, 'credit_minor' => $line->debit_minor, 'description' => 'Reversal of '.$entry->entry_number])->all();
        $reversal = $this->createJournalEntry(tenantId: $entry->tenant_id, facilityId: $entry->facility_id, entryDate: now()->toDateString(), periodId: $entry->period_id, description: 'Reversal of '.$entry->entry_number.' - '.$reason, lines: $reversalLines, sourceType: 'reversal', sourceId: $entry->getKey(), reference: $entry->entry_number, createdBy: $actorId);
        $this->reviewEntry($reversal, $actorId);
        $this->postEntry($reversal, $actorId);
        DB::table('journal_entries')->where('id', $entry->getKey())->update(['status' => JournalEntry::STATUS_REVERSED, 'reversed_by_entry_id' => $reversal->getKey(), 'updated_by' => $actorId]);

        return $reversal->refresh();
    }

    public function trialBalance(string $tenantId, ?string $facilityId, string $periodId): array
    {
        return DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->join('accounts as a', 'a.id', '=', 'jl.account_id')->where('jl.tenant_id', $tenantId)->where('je.period_id', $periodId)->where('je.status', JournalEntry::STATUS_POSTED)->when($facilityId, fn ($q) => $q->where('jl.facility_id', $facilityId))->groupBy('jl.account_id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')->get(['jl.account_id', 'a.code', 'a.name', 'a.type', DB::raw('SUM(jl.debit_minor) as debit_total'), DB::raw('SUM(jl.credit_minor) as credit_total')])->map(fn ($r) => ['account_id' => $r->account_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'debit_total' => (int) $r->debit_total, 'credit_total' => (int) $r->credit_total, 'balance' => (int) $r->debit_total - (int) $r->credit_total])->all();
    }

    public function accountBalance(string $tenantId, ?string $facilityId, string $accountId): int
    {
        return (int) DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('jl.tenant_id', $tenantId)->where('jl.account_id', $accountId)->where('je.status', JournalEntry::STATUS_POSTED)->when($facilityId, fn ($q) => $q->where('jl.facility_id', $facilityId))->sum(DB::raw('jl.debit_minor - jl.credit_minor'));
    }

    private function nextEntryNumber(string $tenantId): string
    {
        $prefix = 'JE-'.date('Ym').'-';
        $last = JournalEntry::query()->where('tenant_id', $tenantId)->where('entry_number', 'like', $prefix.'%')->orderByDesc('entry_number')->value('entry_number');
        if ($last === null) {
            return $prefix.'0001';
        }
        $seq = (int) substr($last, -4) + 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
