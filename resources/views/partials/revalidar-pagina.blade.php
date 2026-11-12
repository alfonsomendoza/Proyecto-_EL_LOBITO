{{--
    Revalidación al volver con el botón «Atrás» o con la bfcache.

    `Cache-Control: no-store` impide guardar la página, pero algunos
    navegadores (Safari/Firefox) pueden restaurar una copia desde la bfcache
    al usar «Atrás». `event.persisted` es true justo en ese caso. Recargar
    obliga a pedir la pantalla al servidor: si la sesión ya se cerró, el
    middleware `auth` redirige al login; si sigue vigente, la página se
    vuelve a mostrar sin reutilizar datos del usuario anterior.

    No se invalida la sesión desde JavaScript: el servidor sigue siendo la
    única autoridad sobre la autenticación.
--}}
<script>
    (function () {
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
    })();
</script>
