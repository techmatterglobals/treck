import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Real-time transport (Laravel Reverb via the Pusher protocol).
 *
 * Reverb is OPTIONAL. Echo is initialized only when a Reverb app key is present
 * in the built assets (VITE_REVERB_APP_KEY). When it is absent — the default
 * production posture with BROADCAST_CONNECTION=log — this module is a no-op:
 * `window.Echo` is left undefined, no WebSocket connection is attempted, and the
 * app runs normally. The dashboards stay fresh via Livewire polling
 * (`wire:poll`), so realtime is a pure enhancement, never a requirement.
 *
 * No developer has to comment/uncomment imports: `bootstrap.js` always imports
 * this file, and the guard below decides at runtime whether to connect.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else if (import.meta.env.DEV) {
    // Dev-only hint; silent in production builds.
    console.info('[Treck] Reverb not configured (VITE_REVERB_APP_KEY empty) — realtime disabled, using Livewire polling.');
}
