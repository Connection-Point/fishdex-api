<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    protected bool $seedCatalogue = false;

    public function test_register_login_me_logout(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $register = $this->postJson('/api/v1/auth/register', [
            'email' => 'Ali@Example.com',
            'password' => 'toman-hunter-99',
            'display_name' => 'Ali',
            'handle' => 'ali_toman',
            'device_name' => 'pixel-8',
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'ali@example.com')
            ->assertJsonPath('data.user.country', 'MY')
            ->assertJsonMissingPath('data.user.password');

        $this->assertStringNotContainsString('"id":1', $register->getContent(), 'internal ids must not leak');

        $this->postJson('/api/v1/auth/login', ['email' => 'ali@example.com', 'password' => 'wrong', 'device_name' => 'x'])
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'invalid_credentials');

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ali@example.com', 'password' => 'toman-hunter-99', 'device_name' => 'pixel-8'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.handle', 'ali_toman')
            ->assertJsonPath('data.scan_quota.limit', 3); // new-account quota

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_google_sign_in_verifies_audience_and_links_account(): void
    {
        config(['services.google.client_ids' => 'app-client-id']);
        Http::fake(['oauth2.googleapis.com/*' => Http::sequence()
            ->push(['iss' => 'accounts.google.com', 'aud' => 'someone-else', 'sub' => '1', 'email' => 'a@b.com', 'email_verified' => 'true'])
            ->push(['iss' => 'accounts.google.com', 'aud' => 'app-client-id', 'sub' => '42', 'email' => 'mei@gmail.com', 'email_verified' => 'true', 'name' => 'Mei Ling', 'given_name' => 'Mei'])
            ->push(['iss' => 'accounts.google.com', 'aud' => 'app-client-id', 'sub' => '42', 'email' => 'mei@gmail.com', 'email_verified' => 'true']),
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 't', 'device_name' => 'd'])->assertStatus(401);

        $this->postJson('/api/v1/auth/google', ['id_token' => 't', 'device_name' => 'd'])
            ->assertOk()->assertJsonPath('data.is_new', true)->assertJsonPath('data.user.email_verified', true);

        $this->postJson('/api/v1/auth/google', ['id_token' => 't', 'device_name' => 'd'])
            ->assertOk()->assertJsonPath('data.is_new', false);

        $this->assertSame(1, User::count());
    }

    public function test_account_deletion_requires_confirmation_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-1']);
        $token = $user->createToken('d')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/me', ['confirmation' => 'nope'])->assertStatus(422);
        $this->withToken($token)->deleteJson('/api/v1/me', ['confirmation' => 'DELETE', 'password' => 'secret-password-1'])
            ->assertStatus(202)->assertJsonPath('data.status', 'pending_deletion');

        $this->assertSame('pending_deletion', $user->fresh()->status);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_old_app_versions_are_told_to_update(): void
    {
        config(['fishdex.min_supported_app_version' => '1.2.0']);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->withHeader('X-App-Version', '1.1.9+40')->getJson('/api/v1/me')
            ->assertStatus(426)->assertJsonPath('code', 'app_version_unsupported');
        $this->actingAs($user, 'sanctum')->withHeader('X-App-Version', '1.2.0+41')->getJson('/api/v1/me')->assertOk();
    }
}
