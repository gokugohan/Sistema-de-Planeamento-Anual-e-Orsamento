import './bootstrap';

import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';

// Chart.js is bundled and loaded on demand, only by pages that draw charts.
window.loadChart = () => window.Chart
    ? Promise.resolve(window.Chart)
    : import('chart.js/auto').then((m) => (window.Chart = m.default));

// Alpine can start before this deferred module has run; tell any waiting page we are ready.
window.dispatchEvent(new Event('app:ready'));
