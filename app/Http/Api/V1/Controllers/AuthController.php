<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Actions\AccountActions;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Support\Problem;
use App\Http\Api\V1\Presenters\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function __construct(private readonly AccountActions $accounts, private readonly Presenter $present) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191', 'unique:users,email'],
            'password' => ['required', PasswordRule::min(8)->uncompromised()],
            'display_name' => ['required', 'string', 'max:60'],
            'handle' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_]+$/', 'unique:users,handle'],
            'device_name' => ['required', 'string', 'max:64'],
            'locale' => ['nullable', 'in:en,ms'],
        ]);

        $user = $this->accounts->register($data);
        event(new Registered($user));

        return response()->json(['data' => [
            'token' => $this->accounts->issueToken($user, $data['device_name']),
            'user' => $this->present->user($user),
        ]], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:64'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw Problem::make(401, 'invalid_credentials', 'Email or password is incorrect.');
        }
        if ($user->status === 'suspended') {
            throw Problem::make(403, 'account_suspended', 'This account is suspended.');
        }
        if ($user->status === 'pending_deletion') {
            // Logging in during the grace period cancels deletion.
            $user->forceFill(['status' => 'active', 'deletion_requested_at' => null])->save();
        }

        return response()->json(['data' => [
            'token' => $this->accounts->issueToken($user, $data['device_name']),
            'user' => $this->present->user($user),
        ]]);
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['required', 'string', 'max:64'],
        ]);

        ['user' => $user, 'is_new' => $isNew] = $this->accounts->loginWithGoogle($data['id_token']);

        if ($user->status === 'suspended') {
            throw Problem::make(403, 'account_suspended', 'This account is suspended.');
        }
        if ($user->status === 'pending_deletion') {
            $user->forceFill(['status' => 'active', 'deletion_requested_at' => null])->save();
        }

        return response()->json(['data' => [
            'token' => $this->accounts->issueToken($user, $data['device_name']),
            'user' => $this->present->user($user),
            'is_new' => $isNew,
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(null, 204);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(null, 202);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Always 202: never reveal whether an account exists.
        Password::broker()->sendResetLink(['email' => Str::lower($request->input('email'))]);

        return response()->json(null, 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', PasswordRule::min(8)->uncompromised()],
        ]);

        $status = Password::broker()->reset(
            ['email' => Str::lower($data['email']), 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw Problem::make(422, 'invalid_reset_token', 'This reset link is invalid or has expired.');
        }

        return response()->json(null, 204);
    }
}
