<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Identity\Models\SocialAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\MediaStore;
use App\Domain\Shared\Support\Problem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AccountActions
{
    public function __construct(private readonly MediaStore $media) {}

    public function register(array $data): User
    {
        return User::create([
            'handle' => $data['handle'],
            'display_name' => $data['display_name'],
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
            'country_id' => Country::byIso(config('fishdex.default_country'))->id,
            'locale' => $data['locale'] ?? 'en',
        ]);
    }

    public function issueToken(User $user, string $deviceName): string
    {
        // One token per device: replace any previous token for this device.
        $user->tokens()->where('name', $deviceName)->delete();

        return $user->createToken($deviceName, ['*'], now()->addDays(config('fishdex.tokens.expiration_days')))->plainTextToken;
    }

    /**
     * Verify a Google ID token server-side and find/link/create the user.
     *
     * @return array{user: User, is_new: bool}
     */
    public function loginWithGoogle(string $idToken): array
    {
        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        $claims = $response->json() ?? [];

        $allowedAudiences = array_filter(explode(',', (string) config('services.google.client_ids')));
        $validIssuer = in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true);

        if (! $response->successful() || ! $validIssuer || ! in_array($claims['aud'] ?? null, $allowedAudiences, true)
            || ($claims['email_verified'] ?? 'false') !== 'true' || empty($claims['sub']) || empty($claims['email'])) {
            throw Problem::make(401, 'unauthenticated', 'Google sign-in could not be verified.');
        }

        return DB::transaction(function () use ($claims) {
            $social = SocialAccount::query()->where('provider', 'google')->where('provider_user_id', $claims['sub'])->first();
            if ($social) {
                return ['user' => $social->user, 'is_new' => false];
            }

            $email = Str::lower($claims['email']);
            $user = User::query()->where('email', $email)->first();
            $isNew = $user === null;

            if ($isNew) {
                $user = User::create([
                    'handle' => $this->uniqueHandle($claims['given_name'] ?? Str::before($email, '@')),
                    'display_name' => Str::limit($claims['name'] ?? Str::before($email, '@'), 60, ''),
                    'email' => $email,
                    'password' => null,
                    'country_id' => Country::byIso(config('fishdex.default_country'))->id,
                ]);
            }

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => $claims['sub'], 'email' => $email]);

            return ['user' => $user, 'is_new' => $isNew];
        });
    }

    public function requestDeletion(User $user): void
    {
        $user->forceFill(['status' => 'pending_deletion', 'deletion_requested_at' => now()])->save();
        $user->tokens()->delete();
        $user->devices()->update(['fcm_token' => null]);
    }

    /** After the grace period: remove photos and personal data, then the account. */
    public function purge(User $user): void
    {
        Media::query()->where('user_id', $user->id)->where('status', '!=', 'purged')
            ->each(fn (Media $m) => $this->media->purge($m));

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->forceDelete(); // FK cascades remove encounters, collection, devices, stats, scans
        });
    }

    private function uniqueHandle(string $seed): string
    {
        $base = Str::limit(preg_replace('/[^a-z0-9_]/', '', Str::lower(Str::ascii($seed))) ?: 'angler', 20, '');
        $handle = $base;
        while (User::withTrashed()->where('handle', $handle)->exists()) {
            $handle = $base.random_int(100, 9999);
        }

        return $handle;
    }
}
