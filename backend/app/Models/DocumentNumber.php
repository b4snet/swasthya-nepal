<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentNumber extends Model
{
    protected $fillable = [
        'tenant_id', 'document_type', 'prefix', 'sequence', 'full_number', 'facility_id',
    ];

    /**
     * Document type prefix mapping.
     */
    public const PREFIXES = [
        'form' => 'FM',
        'prescription' => 'RX',
        'lab_order' => 'LO',
        'lab_report' => 'LR',
        'radiology' => 'RD',
        'invoice' => 'INV',
        'receipt' => 'RCP',
        'referral' => 'REF',
        'consent' => 'CON',
        'admission' => 'ADM',
        'discharge' => 'DSC',
        'procedure' => 'PRC',
        'blood_unit' => 'BU',
        'sample' => 'SPL',
        'appointment' => 'APT',
        'encounter' => 'ENC',
    ];
}
