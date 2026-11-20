<?php
// lo corre el cron de Hestia cada 5 minutos:  php /ruta/a/inventarioti/pagina_web_cron.php
// manda un correo por cada actualizacion de la pagina web (las que no tuvieron cambios no se avisan)
// desde el navegador no hace nada
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/pagina_web.php';

[$ok, $txt] = correr_aviso_web('cron');
echo $txt . "\n";
exit($ok ? 0 : 1);
