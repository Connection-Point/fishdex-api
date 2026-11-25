<?php

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

/**
 * FCM data messages ("nudges"). The app always re-fetches via the API; a push
 * only tells it something changed, so no personal data is put in the payload.
 */
class PushNotifier
{
    public function sendData(User $user, array $data): void
    {
        if (! config('firebase.projects.app.credentials')) {
            return; // FCM not configured (local/dev) — polling still works.
        }

        $tokens = $user->devices()->whereNotNull('fcm_token')->pluck('fcm_token')->all();
        if (! $tokens) {
            return;
        }

        try {
            $messaging = app(Messaging::class);
            $report = $messaging->sendMulticast(
                CloudMessage::new()->withData(array_map('strval', $data))->withHighestPossiblePriority(),
                $tokens,
            );

            // Remove tokens FCM says are dead.
            $invalid = array_merge($report->invalidTokens(), $report->unknownTokens());
            if ($invalid) {
                Device::query()->whereIn('fcm_token', $invalid)->update(['fcm_token' => null]);
            }
        } catch (Throwable $e) {
            Log::warning('FCM send failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
