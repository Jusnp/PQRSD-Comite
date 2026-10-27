{{-- Aplica tema desde cookie antes del paint si el HTML aún no trae data-theme correcto. --}}
<script>
(function () {
    try {
        var match = document.cookie.match(/(?:^|; )pqrsd_theme=(light|dark)(?:;|$)/);
        var fromCookie = match ? match[1] : null;
        var current = document.documentElement.getAttribute('data-theme');
        @auth
        {{-- Autenticado: el servidor ya resolvió preferencia de usuario; no sobrescribir. --}}
        if (!current || (current !== 'light' && current !== 'dark')) {
            document.documentElement.setAttribute('data-theme', fromCookie === 'dark' ? 'dark' : 'light');
        }
        @else
        if (fromCookie === 'dark' || fromCookie === 'light') {
            document.documentElement.setAttribute('data-theme', fromCookie);
        } else if (!current || (current !== 'light' && current !== 'dark')) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
        @endauth
    } catch (e) {}
})();
</script>
