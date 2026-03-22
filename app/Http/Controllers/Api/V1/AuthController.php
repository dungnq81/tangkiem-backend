<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * API Authentication Controller.
 *
 * Handles token-based authentication for the Next.js frontend:
 * - Register: create account → return bearer token
 * - Login: validate credentials → return bearer token
 * - Logout: revoke current token
 * - Forgot/Reset Password: email-based password reset flow
 *
 * All auth endpoints require API domain validation (api.domain:user).
 * Login/register have additional rate limiting to prevent brute force.
 */
class AuthController extends Controller
{
    /**
     * Register a new user account.
     *
     * POST /v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'     => $request->validated('name'),
            'email'    => $request->validated('email'),
            'password' => $request->validated('password'), // auto-hashed via cast
        ]);

        $deviceName = $request->validated('device_name', 'web');
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công.',
            'data'    => [
                'user'  => new UserResource($user->loadCount(['bookmarks', 'readingHistory'])),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Login with email and password.
     *
     * POST /v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Invalid credentials
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Email hoặc mật khẩu không đúng.',
            ], 401);
        }

        // Banned user check
        if ($user->isBanned()) {
            return response()->json([
                'success' => false,
                'message' => $user->getBanMessage(),
                'error'   => 'banned',
            ], 403);
        }

        // Inactive user check
        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã bị vô hiệu hóa.',
                'error'   => 'inactive',
            ], 403);
        }

        $deviceName = $request->validated('device_name', 'web');
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công.',
            'data'    => [
                'user'  => new UserResource($user->loadCount(['bookmarks', 'readingHistory'])),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Logout (revoke current token).
     *
     * POST /v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke the token that was used for this request
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đã đăng xuất.',
        ]);
    }

    /**
     * Send password reset link via email.
     *
     * POST /v1/auth/forgot-password
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        // Resolve frontend URL from the API domain making this request.
        // ValidateApiDomain middleware attaches the domain model to the request.
        // In local dev (no keys), api_domain is null → fall back to FRONTEND_URL env.
        $apiDomain = $request->attributes->get('api_domain');
        $frontendUrl = $apiDomain
            ? 'https://' . rtrim($apiDomain->domain, '/')
            : rtrim((string) env('FRONTEND_URL', 'https://tangkiem.xyz'), '/');

        // Set reset URL dynamically per-request (safe: PHP is single-threaded)
        ResetPassword::createUrlUsing(function ($notifiable, string $token) use ($frontendUrl) {
            return $frontendUrl . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        Password::sendResetLink(
            $request->only('email')
        );

        // Always return success to prevent email enumeration
        return response()->json([
            'success' => true,
            'message' => 'Nếu email tồn tại trong hệ thống, bạn sẽ nhận được link đặt lại mật khẩu.',
        ]);
    }

    /**
     * Reset password using token from email.
     *
     * POST /v1/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => $password, // auto-hashed via cast
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke ALL existing tokens (force re-login)
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => $this->translateResetStatus($status),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mật khẩu đã được đặt lại thành công. Vui lòng đăng nhập lại.',
        ]);
    }

    /**
     * Translate Laravel password reset status to Vietnamese.
     */
    private function translateResetStatus(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'Token đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.',
            Password::INVALID_USER  => 'Không tìm thấy tài khoản với email này.',
            Password::RESET_THROTTLED => 'Vui lòng đợi trước khi thử lại.',
            default                 => 'Không thể đặt lại mật khẩu.',
        };
    }
}
