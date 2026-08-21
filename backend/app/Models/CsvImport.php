<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\LaravelUlid\HasUuid;

class CsvImport extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_DRY_RUN = 'dry_run';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const ENTITY_TYPES = [
        'patient', 'medication', 'lab_test', 'charge', 'service',
        'department', 'staff', 'supplier', 'inventory',
    ];

    protected $fillable = [
        'tenant_id', 'facility_id', 'entity_type',
        'file_name', 'file_path',
        'total_rows', 'success_rows', 'error_rows',
        'status', 'field_mapping', 'validation_errors', 'import_errors',
        'metadata', 'imported_by',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'field_mapping' => 'array',
        'validation_errors' => 'array',
        'import_errors' => 'array',
        'metadata' => 'array',
        'total_rows' => 'integer',
        'success_rows' => 'integer',
        'error_rows' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
