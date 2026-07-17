import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Real-time presence broadcasting (M7). Sets up window.Echo for Livewire's
// echo-private listeners. Requires `laravel-echo` + `pusher-js` (npm install).
import './echo';
