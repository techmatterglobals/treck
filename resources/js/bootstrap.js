import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Real-time transport (optional). echo.js self-guards: it only connects when
// Reverb is configured (VITE_REVERB_APP_KEY set). When it isn't, this import is
// a harmless no-op — no WebSocket is attempted — and the dashboards refresh via
// Livewire polling instead. Safe to keep imported unconditionally.
import './echo';
