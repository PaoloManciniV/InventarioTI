<?php
// lo corre el cron de Hestia cada 5 minutos:  php /ruta/a/inventarioti/pagina_web_cron.php
// manda un correo por cada actualizacion de la pagina web (las que no tuvieron cambios no se avisan)
// desde el navegador no hace nada
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/pagina_web.php';

if (!web_configurada()) { echo "Falta WEB_DB_* en el .env\n"; exit(1); }
if (!correo_configurado()) { echo "Correo no configurado\n"; exit(1); }
if (!correos_web()) { echo "Sin destinatarios (MAIL_WEB, MAIL_RESPALDOS o MAIL_INVENTARIO)\n"; exit(1); }
try {
    echo 'Avisadas: ' . avisar_web_nuevas() . "\n";
} catch (Throwable $t) {
    echo 'Error: ' . $t->getMessage() . "\n";
    exit(1);
}
