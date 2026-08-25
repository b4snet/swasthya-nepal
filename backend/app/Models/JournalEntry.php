<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal Entry — the accounting record (DATABASE.md §3.59).
 *
 * Every business event that affects the general ledger creates a journal
 * entry with balanced debit/credit lines. Entries follow the lifecycle:
 * draft → reviewed → posted. Posted entries are immutable; corrections
 * use reversing entries.
 */
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'entry_number',
        'entry_date',
        'period_id',
        'source_type',
        'source_id',
        'description',
        'reference',
        'status',
        'posted_at',
        'posted_by',
        'reversed_by_entry_id',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function period()
    {
        return $this->belongsTo(FinancialPeriod::class, 'period_id');
    }

    public function reversedByEntry()
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    /**
     * Total debits must equal total credits for a balanced entry.
     */
    public function totalDebitMinor(): int
    {
        return (int) $this->lines()->sum('debit_minor');
    }

    public function totalCreditMinor(): int
    {
        return (int) $this->lines()->sum('credit_minor');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebitMinor() === $this->totalCreditMinor();
    }
}
