<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'code', 'name', 'description', 'status', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
