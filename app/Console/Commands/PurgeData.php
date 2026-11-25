<?php

namespace App\Console\Commands;

use App\Domain\Identification\Models\IdentificationAttempt;
use App\Domain\Identity\Actions\AccountActions;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\MediaStore;
use Illuminate\Console\Command;

/** Retention (doc 09 §5): abandoned scan photos, rejected uploads, old AI payloads, deleted accounts. */
class PurgeData extends Command
{
    protected $signature = 'fishdex:purge';

    protected $description = 'Apply data retention rules';

    public function handle(MediaStore $media, AccountActions $accounts): int
    {
        $photos = 0;
        Media::query()
            ->whereNotNull('purge_after')->where('purge_after', '<=', now())
            ->where('status', '!=', 'purged')
            ->whereNotExists(fn ($q) => $q->from('encounter_media')->whereColumn('encounter_media.media_id', 'media.id'))
            ->chunkById(200, function ($chunk) use ($media, &$photos) {
                foreach ($chunk as $m) {
                    $media->purge($m);
                    $photos++;
                }
            });

        $payloads = IdentificationAttempt::query()
            ->whereNotNull('response_json')
            ->where('created_at', '<', now()->subDays(90))
            ->update(['response_json' => null]);

        $users = 0;
        User::query()
            ->where('status', 'pending_deletion')
            ->where('deletion_requested_at', '<=', now()->subDays(config('fishdex.account_deletion_grace_days')))
            ->each(function (User $user) use ($accounts, &$users) {
                $accounts->purge($user);
                $users++;
            });

        $this->info("Purged {$photos} photo(s), {$payloads} AI payload(s), {$users} account(s).");

        return self::SUCCESS;
    }
}
