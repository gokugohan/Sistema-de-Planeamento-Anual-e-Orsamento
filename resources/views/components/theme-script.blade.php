{{--
    Day or night mode, before the page paints (no white flash). The choice is per browser: 'light' (the default),
    'dark', or 'system' (follows the device). setTheme(mode) changes it; pages moved to with wire:navigate keep it.
--}}
<script>
    (function () {
        var key = 'paa-theme', media = window.matchMedia('(prefers-color-scheme: dark)');
        function mode() { try { return localStorage.getItem(key) || 'light'; } catch (e) { return 'light'; } }
        function apply() {
            var m = mode();
            document.documentElement.classList.toggle('dark', m === 'dark' || (m === 'system' && media.matches));
            document.documentElement.dataset.theme = m;
        }
        window.currentTheme = mode;
        window.setTheme = function (m) {
            try { localStorage.setItem(key, m); } catch (e) {}
            apply();
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: m }));
        };
        apply();
        document.addEventListener('livewire:navigated', apply);
        media.addEventListener('change', apply);
    })();

    // The sidebar on a wide screen: full, or folded to a rail of icons (per browser, before the page paints).
    (function () {
        var key = 'paa-sidebar';
        function folded() { try { return localStorage.getItem(key) === 'collapsed'; } catch (e) { return false; } }
        function apply() { document.documentElement.classList.toggle('sidebar-collapsed', folded()); }
        window.sidebarCollapsed = folded;
        window.toggleSidebar = function () {
            var f = !folded();
            try { localStorage.setItem(key, f ? 'collapsed' : 'open'); } catch (e) {}
            document.documentElement.classList.toggle('sidebar-collapsed', f);
            window.dispatchEvent(new CustomEvent('sidebar-changed', { detail: f }));
        };
        apply();
        document.addEventListener('livewire:navigated', apply);
    })();
</script>
