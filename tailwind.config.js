import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * TailAdmin-style design tokens.
 * `indigo` is remapped to the TailAdmin primary (#3C50E0) and `zinc` to the
 * TailAdmin neutral scale (#1C2434 ink / #E2E8F0 stroke), so every existing
 * utility class in the app picks up the new look without per-page edits.
 */
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
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                indigo: {
                    50: '#EFF2FE',
                    100: '#E1E6FC',
                    200: '#C6CEFA',
                    300: '#9EAAF5',
                    400: '#6F80EE',
                    500: '#5468E8',
                    600: '#3C50E0',
                    700: '#3040C0',
                    800: '#2A369A',
                    900: '#263078',
                    950: '#181D4A',
                },
                zinc: {
                    50: '#F7F9FC',
                    100: '#EFF4FB',
                    200: '#E2E8F0',
                    300: '#CBD5E1',
                    400: '#94A3B8',
                    500: '#64748B',
                    600: '#475569',
                    700: '#333A48',
                    800: '#24303F',
                    900: '#1C2434',
                    950: '#10151F',
                },
                body: '#F1F5F9',
                'nav-hover': '#333A48',
                'nav-text': '#DEE4EE',
                'nav-muted': '#8A99AF',
            },
            borderRadius: {
                // TailAdmin favours crisp, low-radius surfaces.
                lg: '0.375rem',
                xl: '0.375rem',
                '2xl': '0.5rem',
                '3xl': '0.5rem',
            },
            fontWeight: {
                black: '700',
                extrabold: '700',
            },
            boxShadow: {
                default: '0px 8px 13px -3px rgba(0, 0, 0, 0.07)',
                card: '0px 1px 3px rgba(0, 0, 0, 0.12)',
                'card-lg': '0px 1px 3px rgba(0, 0, 0, 0.08), 0px 8px 13px -3px rgba(0, 0, 0, 0.07)',
            },
            spacing: {
                68: '17rem',
            },
        },
    },

    plugins: [forms],
};
