import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Real-time presence transport (M7). Uses Laravel Reverb via the Pusher
// protocol. Values come from Vite env (see .env: VITE_REVERB_*). When Reverb is
// not configured the dashboard still renders; it simply will not receive live
// pushes. Livewire discovers this global `window.Echo` automatically for its
// `echo-private:*` listeners, so no per-component wiring is needed.
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
