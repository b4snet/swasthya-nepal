<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal Line — individual debit or credit within a journal entry.
 * Each line references exactly one account. A balanced entry has
 * total debits = total credits across all its lines.
 */
class JournalLine extends Model
{
    /** @use HasFactory<\Database\Factories\JournalLineFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'journal_entry_id',
        'account_id',
        'debit_minor',
        'credit_minor',
        'description',
        'patient_id',
        'invoice_id',
        'payment_id',
        'claim_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
        ];
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
