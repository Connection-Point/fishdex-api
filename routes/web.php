<?php

use App\Domain\Identity\Models\User;
use App\Http\Controllers\CronController;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Route;

// Vercel Cron (serverless hosting). Bearer CRON_SECRET; 404 otherwise.
Route::get('/internal/cron/daily', [CronController::class, 'daily']);

Route::get('/', fn () => response()->json(['name' => 'FishDex API', 'docs' => '/api/v1/health']));

/*
| Email verification link from the verification email. Opened in a browser, so it
| uses a signed URL instead of a session login.
*/
Route::get('/email/verify/{id}/{hash}', function (string $id, string $hash) {
    $user = User::query()->findOrFail($id);

    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return response('<h1>Email verified ✓</h1><p>You can go back to the FishDex app.</p>');
})->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
