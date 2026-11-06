import defaultTheme from 'tailwindcss/defaultTheme';
import defaultColors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

/**
 * Design tokens (design system v3: the TailAdmin palette, a bright brand blue on clean gray and white), in a day
 * and a night mode.
 *
 * `indigo` is the brand color, remapped to the TailAdmin brand scale (#465FFF), and `zinc` is the neutral
 * scale, remapped to the TailAdmin gray, so every existing utility class in the app picks up the system
 * without per-page edits. Rules the scales keep:
 *  - body text zinc-600 or darker, muted text zinc-500 (4.7:1 on white); zinc-400 is for icons and
 *    placeholders and is already dark enough to read (4.5:1);
 *  - primary actions use indigo-600 (7:1 on white) and a visible 2px focus ring;
 *  - semantic colors (emerald, amber, rose) are used for status only, never for decoration.
 *
 * Night mode (class `dark` on <html>): every color is a CSS variable with a day and a night value, so the same
 * classes work in both and no page needs `dark:` variants. Backgrounds and borders read `--c-*`, text reads `--t-*`:
 * at night the pale tints (50–300) become dark tints of their color and the dark text shades (600–950) become light,
 * while solid fills (a bg-indigo-600 button with white text) stay as they are. `bg-white` is the card surface.
 */

/** The one neutral scale (TailAdmin gray). zinc, gray and slate all point here so no page can be lighter than the rest. */
const neutral = {
    50: '#F9FAFB',
    100: '#F2F4F7',
    200: '#E4E7EC',
    300: '#D0D5DD',
    400: '#667085', // the TailAdmin gray-500 (its own 400 is too light to read): 4.8:1 on white, safe for text and icons
    500: '#5D6679',
    600: '#475467',
    700: '#344054',
    800: '#1D2939',
    900: '#101828',
    950: '#0C111D',
};

/** The TailAdmin brand scale: 500 is the brand blue, 600 the one for text and buttons (6.5:1 on white). */
const brand = {
    50: '#ECF3FF',
    100: '#DDE9FF',
    200: '#C2D6FF',
    300: '#9CB9FF',
    400: '#7592FF',
    500: '#465FFF',
    600: '#3641F5',
    700: '#2A31D8',
    800: '#252DAE',
    900: '#262E89',
    950: '#161950',
};

const scales = {
    neutral,
    indigo: brand,
    emerald: { ...defaultColors.emerald, 600: '#047857', 700: '#065F46' },
    green: { ...defaultColors.green, 500: '#15803D', 600: '#15803D', 700: '#166534' },
    amber: { ...defaultColors.amber, 600: '#B45309' },
    rose: { ...defaultColors.rose, 500: '#E11D48' },
    red: defaultColors.red,
    sky: defaultColors.sky,
    blue: defaultColors.blue,
    violet: defaultColors.violet,
    purple: defaultColors.purple,
    yellow: defaultColors.yellow,
    orange: defaultColors.orange,
    teal: defaultColors.teal,
};

/** Night: the page, the cards (bg-white) and the neutral scale (TailAdmin dark). */
const night = { body: '#101828', surface: '#1A2231' };
const neutralNight = {
    c: { 50: '#1F2938', 100: '#253043', 200: '#2E3A4D', 300: '#3D4A5E', 400: '#667085', 500: '#7A8499', 600: '#98A2B3' },
    t: { 300: '#5D6679', 400: '#8A94A6', 500: '#98A2B3', 600: '#B4BCC8', 700: '#CDD3DC', 800: '#E4E7EC', 900: '#F2F4F7', 950: '#FFFFFF' },
};

const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const rgb = (hex) => {
    const n = parseInt(hex.slice(1), 16);
    return `${(n >> 16) & 255} ${(n >> 8) & 255} ${n & 255}`;
};
const mix = (hex, over, weight) => { // `weight` of hex laid over the color `over`
    const a = rgb(hex).split(' ').map(Number);
    const b = rgb(over).split(' ').map(Number);
    return a.map((v, i) => Math.round(v * weight + b[i] * (1 - weight))).join(' ');
};

const day = { '--body': rgb('#F9FAFB'), '--surface': rgb('#FFFFFF') };
const dark = { '--body': rgb(night.body), '--surface': rgb(night.surface), 'color-scheme': 'dark' };
for (const [name, scale] of Object.entries(scales)) {
    for (const s of SHADES) {
        day[`--c-${name}-${s}`] = day[`--t-${name}-${s}`] = rgb(scale[s]);
        if (name === 'neutral') {
            dark[`--c-${name}-${s}`] = rgb(neutralNight.c[s] ?? scale[s]);
            dark[`--t-${name}-${s}`] = rgb(neutralNight.t[s] ?? scale[s]);
            continue;
        }
        const tint = { 50: 0.14, 100: 0.2, 200: 0.3, 300: 0.45 }[s];
        dark[`--c-${name}-${s}`] = tint ? mix(scale[500], night.surface, tint) : rgb(scale[s]);
        const light = { 400: 300, 500: 400, 600: 400, 700: 300, 800: 200, 900: 100, 950: 50 }[s];
        dark[`--t-${name}-${s}`] = rgb(light ? scale[light] : scale[s]);
    }
}

const tokens = (kind) => {
    const out = {};
    for (const [name] of Object.entries(scales)) {
        const scale = Object.fromEntries(SHADES.map((s) => [s, `rgb(var(--${kind}-${name}-${s}) / <alpha-value>)`]));
        if (name === 'neutral') {
            out.zinc = out.gray = out.slate = scale;
        } else {
            out[name] = scale;
        }
    }
    return out;
};

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Outfit', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ...tokens('c'),
                body: 'rgb(var(--body) / <alpha-value>)',
                // the sidebar is white, as in TailAdmin
                'nav-hover': 'rgb(var(--c-neutral-100) / <alpha-value>)',
                'nav-text': 'rgb(var(--t-neutral-700) / <alpha-value>)',
                'nav-muted': 'rgb(var(--t-neutral-400) / <alpha-value>)',
            },
            // text reads its own variables: light at night; text-white stays white (on the solid buttons)
            textColor: tokens('t'),
            // bg-white is the surface of cards, bars and dialogs: dark at night
            backgroundColor: { white: 'rgb(var(--surface) / <alpha-value>)' },
            borderRadius: {
                lg: '0.5rem',
                xl: '0.75rem',
                '2xl': '1rem',
                '3xl': '1.25rem',
            },
            fontWeight: {
                black: '700',
                extrabold: '700',
            },
            boxShadow: {
                default: '0 12px 16px -4px rgba(16, 24, 40, 0.08), 0 4px 6px -2px rgba(16, 24, 40, 0.03)',
                card: '0 1px 2px 0 rgba(16, 24, 40, 0.05)',
                'card-lg': '0 4px 8px -2px rgba(16, 24, 40, 0.10), 0 2px 4px -2px rgba(16, 24, 40, 0.06)',
            },
            spacing: {
                68: '17rem',
            },
        },
    },

    plugins: [
        forms,
        plugin(({ addBase }) => addBase({ ':root': day, ':root.dark': dark })),
    ],
};
