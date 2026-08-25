<?php

use App\Console\Commands\AutoEscalateCriticalValues;
use Illuminate\Support\Facades\Schedule;

// Auto-escalate unacknowledged critical lab values every 15 minutes.
// This is a patient-safety requirement: critical values that remain
// unacknowledged after the timeout are automatically escalated to
// ensure they are never silently lost (CLINICAL_SAFETY §7).
Schedule::command(AutoEscalateCriticalValues::class)->everyFifteenMinutes();
