<script>
(function () {
    const html = document.documentElement;
    const themeBtn = document.getElementById('pqrsd-theme-toggle');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    function syncToggle(theme) {
        if (!themeBtn) {
            return;
        }
        themeBtn.dataset.themeCurrent = theme;
        themeBtn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        themeBtn.setAttribute(
            'aria-label',
            theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'
        );
        themeBtn.setAttribute('title', theme === 'dark' ? 'Tema oscuro activo' : 'Tema claro activo');
        themeBtn.textContent = theme === 'dark' ? '☀️' : '🌙';
    }

    function applyTheme(next) {
        html.setAttribute('data-theme', next);
        syncToggle(next);
    }

    syncToggle(html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

    themeBtn?.addEventListener('click', () => {
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        document.cookie = 'pqrsd_theme=' + next + ';path=/;max-age=31536000;SameSite=Lax';

        fetch(@json(route('preferencias.tema')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ theme: next }),
            credentials: 'same-origin'
        }).catch(() => {});
    });
})();
</script>
