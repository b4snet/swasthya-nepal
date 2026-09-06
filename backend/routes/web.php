<?php

use App\Models\User;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Swasthya is API-first (MASTER_RULES.md §2.2). There is no web UI: the
| SPA is served by the frontend build, not by Laravel.
|
| The single exception is the named 'login' route. Laravel's Authenticate
| middleware redirects unauthenticated non-JSON requests there; it must
| exist (otherwise the redirect itself 500s) and it must return the standard
| 401 envelope — never a web login page that does not exist.
|
| One-time bootstrap endpoint — REMOVE AFTER FIRST USE
*/

Route::get('login', function () {
    return Envelope::error(
        ErrorCodes::INVALID_TOKEN,
        'Authentication required.',
        401,
        request: request(),
    );
})->name('login');

if (env('ALLOW_BOOTSTRAP')) {
    Route::get('bootstrap/create-admin', function () {
        $admin = User::where('email', 'admin@hospital.example')->first();
        if ($admin) {
            return ['skip' => true, 'email' => $admin->email];
        }

        $user = User::create([
            'email' => 'admin@hospital.example',
            'password' => bcrypt('ChangeMe123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('hospital_admin');

        return ['success' => true, 'email' => $user->email];
    });
}
