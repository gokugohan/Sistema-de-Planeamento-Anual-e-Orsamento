import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Design tokens (design system v3: the TailAdmin palette, a bright brand blue on clean gray and white).
 *
 * `indigo` is the brand color, remapped to the TailAdmin brand scale (#465FFF), and `zinc` is the neutral
 * scale, remapped to the TailAdmin gray, so every existing utility class in the app picks up the system
 * without per-page edits. Rules the scales keep:
 *  - body text zinc-600 or darker, muted text zinc-500 (4.7:1 on white); zinc-400 is for icons and
 *    placeholders and is already dark enough to read (4.5:1);
 *  - primary actions use indigo-600 (7:1 on white) and a visible 2px focus ring;
 *  - semantic colours (emerald, amber, rose) are used for status only, never for decoration.
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

/** @type {import('tailwindcss').Config} */
export default {
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
                indigo: { // the TailAdmin brand scale: 500 is the brand blue, 600 the one for text and buttons (6.5:1 on white)
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
                },
                emerald: { 600: '#047857', 700: '#065F46' },
                green: { 500: '#15803D', 600: '#15803D', 700: '#166534' },
                amber: { 600: '#B45309' },
                rose: { 500: '#E11D48' },
                zinc: neutral,
                gray: neutral,
                slate: neutral,
                body: '#F9FAFB',
                // the sidebar is white, as in TailAdmin
                'nav-hover': '#F2F4F7',
                'nav-text': '#344054',
                'nav-muted': '#667085',
            },
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

    plugins: [forms],
};
