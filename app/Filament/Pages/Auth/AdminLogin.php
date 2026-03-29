<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\Setting;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Custom admin login page with optional Cloudflare Turnstile protection.
 *
 * Conditionally renders and validates the Turnstile widget
 * based on admin settings (security.turnstile_enabled).
 *
 * Validates directly via Cloudflare API (not using the package's
 * broken validation rule) for reliability.
 */
class AdminLogin extends BaseLogin
{
    /**
     * Turnstile token set by Alpine.js via $wire.set() from the blade widget.
     */
    public ?string $turnstileToken = null;

    /**
     * Extend the default form to add Turnstile widget.
     */
    public function form(Schema $schema): Schema
    {
        $components = [
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ];

        if ($this->isTurnstileEnabled()) {
            $components[] = $this->getTurnstileFormComponent();
        }

        return $schema->components($components);
    }

    /**
     * Override authenticate to add Turnstile validation.
     */
    public function authenticate(): ?\Filament\Auth\Http\Responses\Contracts\LoginResponse
    {
        if ($this->isTurnstileEnabled()) {
            $this->validateTurnstile();
        }

        return parent::authenticate();
    }

    /**
     * Validate the Turnstile response token server-side.
     *
     * Calls Cloudflare's siteverify API directly instead of using the
     * package's validation rule (which has inverted logic in v3).
     */
    protected function validateTurnstile(): void
    {
        $this->applyTurnstileConfig();

        if (empty($this->turnstileToken)) {
            throw ValidationException::withMessages([
                'data.email' => 'Vui lòng hoàn thành xác minh bảo mật Turnstile.',
            ]);
        }

        try {
            $secretKey = config('services.turnstile.secret', '');

            $response = Http::timeout(10)
                ->retry(2, 100)
                ->asForm()
                ->acceptJson()
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret'   => $secretKey,
                    'response' => $this->turnstileToken,
                    'remoteip' => request()->ip(),
                ]);

            if (!$response->ok()) {
                Log::warning('Turnstile API HTTP error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                // On HTTP error, let the user through (fail open)
                // so they're not locked out if Cloudflare is down
                return;
            }

            $result = $response->json();

            if (!empty($result['success'])) {
                // Turnstile verification passed
                return;
            }

            // Verification failed
            Log::info('Turnstile verification failed', [
                'error_codes' => $result['error-codes'] ?? [],
            ]);

            $this->turnstileToken = null;

            throw ValidationException::withMessages([
                'data.email' => 'Xác minh bảo mật thất bại. Vui lòng thử lại.',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Catch any unexpected error (network timeout, etc.)
            // Fail open — don't lock out admin if Turnstile service is down
            Log::error('Turnstile validation error', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the Turnstile widget as a form component.
     */
    protected function getTurnstileFormComponent(): Component
    {
        return Group::make()
            ->schema([
                \Filament\Schemas\Components\View::make('filament.components.turnstile-widget'),
            ]);
    }

    /**
     * Check if Turnstile is enabled via admin settings.
     */
    protected function isTurnstileEnabled(): bool
    {
        try {
            $enabled = (bool) Setting::get('security.turnstile_enabled', false);

            if (!$enabled) {
                return false;
            }

            // Check keys: DB first, then .env fallback
            $siteKey = Setting::get('security.turnstile_site_key', '') ?: config('services.turnstile.key', '');
            $secretKey = Setting::get('security.turnstile_secret_key', '') ?: config('services.turnstile.secret', '');

            return !empty($siteKey) && !empty($secretKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply Turnstile config from DB settings at runtime.
     */
    protected function applyTurnstileConfig(): void
    {
        $siteKey = Setting::get('security.turnstile_site_key', '');
        $secretKey = Setting::get('security.turnstile_secret_key', '');

        if (!empty($siteKey)) {
            config(['services.turnstile.key' => $siteKey]);
        }

        if (!empty($secretKey)) {
            config(['services.turnstile.secret' => $secretKey]);
        }
    }
}
