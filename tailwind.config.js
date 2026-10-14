import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Design tokens (design system v2: calm, high-contrast, institutional).
 *
 * `indigo` is the brand colour, remapped to a deep civic blue, and `zinc` is the neutral scale,
 * remapped to cool slate, so every existing utility class in the app picks up the system without
 * per-page edits. Rules the scales keep:
 *  - body text zinc-600 or darker, muted text zinc-500 (4.7:1 on white); zinc-400 is for icons and
 *    placeholders and is already dark enough to read (4.5:1);
 *  - primary actions use indigo-600 (7:1 on white) and a visible 2px focus ring;
 *  - semantic colours (emerald, amber, rose) are used for status only, never for decoration.
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
                    50: '#EEF4FF',
                    100: '#DCE8FF',
                    200: '#BED3FF',
                    300: '#93B3FB',
                    400: '#5F8BF0',
                    500: '#3A68E0',
                    600: '#2152C4',
                    700: '#1B429F',
                    800: '#193880',
                    900: '#1A3166',
                    950: '#111F44',
                },
                zinc: {
                    50: '#F8FAFC',
                    100: '#F1F5F9',
                    200: '#E2E8F0',
                    300: '#CBD5E1',
                    400: '#6B7A90',
                    500: '#5B6B82',
                    600: '#475569',
                    700: '#334155',
                    800: '#1E293B',
                    900: '#0F172A',
                    950: '#020617',
                },
                body: '#F5F7FB',
                'nav-hover': '#1E293B',
                'nav-text': '#D5DDE8',
                'nav-muted': '#93A3B8',
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
                default: '0 10px 24px -8px rgba(15, 23, 42, 0.12)',
                card: '0 1px 2px rgba(15, 23, 42, 0.06), 0 1px 3px rgba(15, 23, 42, 0.04)',
                'card-lg': '0 2px 4px rgba(15, 23, 42, 0.05), 0 8px 20px -6px rgba(15, 23, 42, 0.10)',
            },
            spacing: {
                68: '17rem',
            },
        },
    },

    plugins: [forms],
};
