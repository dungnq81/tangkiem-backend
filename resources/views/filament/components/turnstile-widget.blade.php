{{--
    Turnstile widget component for the admin login form.

    Reads site key from DB (security.turnstile_site_key) or .env fallback.

    Uses Alpine.js $wire to send token to Livewire component.
    Flow: Turnstile callback → browser CustomEvent → Alpine x-on → $wire.set()
    This avoids @this which is not available in Filament\Schemas\Components\View context.
--}}

@php
    $siteKey = \App\Models\Setting::get('security.turnstile_site_key', '') ?: config('services.turnstile.key', '');
@endphp

@if (!empty($siteKey))
    {{-- Load Turnstile API script --}}
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    {{--
        wire:ignore  → prevent Livewire from destroying the Turnstile widget on re-render
        x-data       → enable Alpine context so $wire is available
        x-on:...     → listen for custom browser event and forward token to Livewire
    --}}
    <div
        class="mt-1 flex justify-center"
        x-data
        x-on:turnstile-success.window="$wire.set('turnstileToken', $event.detail.token)"
        x-on:turnstile-reset.window="$wire.set('turnstileToken', null)"
        wire:ignore
    >
        <div
            class="cf-turnstile"
            data-sitekey="{{ $siteKey }}"
            data-theme="auto"
            data-action="admin-login"
            data-language="vi"
            data-callback="onTurnstileSuccess"
            data-expired-callback="onTurnstileExpired"
            data-error-callback="onTurnstileError"
        ></div>
    </div>

    <script>
        function onTurnstileSuccess(token) {
            window.dispatchEvent(new CustomEvent('turnstile-success', {
                detail: { token: token }
            }));
        }

        function onTurnstileExpired() {
            window.dispatchEvent(new CustomEvent('turnstile-reset'));
            if (window.turnstile) {
                window.turnstile.reset();
            }
        }

        function onTurnstileError(error) {
            console.warn('Turnstile error:', error);
            window.dispatchEvent(new CustomEvent('turnstile-reset'));
        }
    </script>
@endif
