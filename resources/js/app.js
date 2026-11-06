import './bootstrap';
import './datepicker';

import '@fontsource/outfit/400.css';
import '@fontsource/outfit/500.css';
import '@fontsource/outfit/600.css';
import '@fontsource/outfit/700.css';

// A color token of the current day or night mode, for drawings that cannot use classes (the charts).
window.themeColor = (name) => `rgb(${getComputedStyle(document.documentElement).getPropertyValue(name).trim()})`;

// Chart.js is bundled and loaded on demand, only by pages that draw charts.
window.loadChart = () => window.Chart
    ? Promise.resolve(window.Chart)
    : import('chart.js/auto').then((m) => (window.Chart = m.default));

// Alpine can start before this deferred module has run; tell any waiting page we are ready.
window.dispatchEvent(new Event('app:ready'));
