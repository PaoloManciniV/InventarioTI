# Inventario TI

Inventario de equipos de sistemas con actas de entrega, préstamo y devolución firmadas en digital.
Reemplaza el Excel y las actas en papel.

PHP sin framework, JavaScript puro y MySQL/MariaDB. Sin Composer ni build: se sube la carpeta y funciona.

> Este repositorio no trae datos: ni la base, ni el `.env`, ni lo subido a `uploads/`.

## Módulos

| Módulo | Para qué |
| --- | --- |
| Vista general | Resumen: equipos por estado y por categoría, actas pendientes y últimos movimientos. Todo es clic: cada movimiento lleva al acta, a la IP o a la hoja de vida del equipo |
| Tareas del equipo | Checklist del grupo de sistemas. La tarea se escoge de una lista por categorías (la lista la administran quienes gestionan usuarios) y se le escribe una observación; opcionalmente la persona y el equipo relacionados. Puede ser propia (solo la ve quien la crea), para todo el grupo (alguien la "toma") o asignada a un compañero. Cada tarea tiene notas del caso para saber hasta dónde llegó el otro, y se puede transferir a cualquiera del equipo con el motivo. Avisa por correo al crear, tomar, transferir, cambiar la fecha de entrega, cumplir y cuando se vence; cada mañana manda un resumen (`tareas_cron.php` cada hora en el cron). Incluye un bloc de notas personal tipo stickies que solo ve su dueño |
| Solicitudes a administración | Formato SGSST-AC-F55 "Requerimiento de materiales/suministros" (Dpto SISTEMAS). El auxiliar radica la solicitud (tipo de compra, trabajo, artículos con unidad, especificaciones y costo estimado); la aprueban o rechazan quienes administran usuarios. Al aprobarla se libera el PDF igual al de papel (hoja carta, tercio superior con guía de recorte y un código diminuto), que se descarga una sola vez; si hace falta, se vuelve a liberar dejando el motivo. Luego: entregada a administración, en compra, recibida (costo real y factura). Historial de quién radicó, quién autorizó y quién imprimió; resumen de lo que se pide y cuántas veces; Excel. Avisos por correo en cada paso y los lunes las atrasadas. PDF con FPDF (`inc/fpdf`, licencia libre) |
| Inventario | Equipos e items con estado (disponible, asignado, prestado, cedido, dañado, por ubicar…), colores por tipo en los disponibles y una nota visible (nuevo, usado, pantalla rota…) que se pone, edita o quita desde la misma lista. Cada equipo tiene hoja de vida, "quién lo ha tenido y cuánto duró" y "Ver cambios". El responsable se puede cambiar sin acta (queda en el historial) y los combos (teclado + mouse) se separan para entregar solo una parte |
| Colaboradores | Quién tiene qué, actas de cada persona, extensión MicroSIP si llama desde el PC y "recoger todo" para retiros |
| Actas y firmas | Entregas, préstamos y devoluciones. Se firman con el dedo en el celular, por enlace o por correo. La copia firmada le llega al correo a la persona y a sistemas. En un préstamo todo queda en el inventario, por mínimo que sea |
| Mantenimientos | Preventivos cada N meses y correctivos, con firma del usuario |
| Red y extensiones | Mapa completo de IPs (.1 a .255, libres en verde, reservadas en amarillo, repetidas en rojo). Una IP libre se asigna con doble clic. Directorio de extensiones (teléfono IP o MicroSIP) y servidores resaltados |
| Impresoras | Impresoras por piso, cambios de tóner y reparaciones o revisiones por fuera (proveedor, cotización, costo final y factura) |
| Informe anual | Conteo de equipos por tipo para contabilidad, exportable a Excel |
| Herramientas | Programas, portales, configuraciones y archivos de descarga. Incluye el generador de firmas de correo (PNG con QR vCard) y el de stickers de ubicaciones para impresora Zebra (Code 128 / ZPL), los dos en la web |
| Contactos de soporte y compras | Proveedores, soporte y escalamiento, con llamada y WhatsApp |
| Copias de seguridad | Control de la copia desconectada al disco externo. Un script en el disco (`REPORTAR_COPIA.bat`, se descarga ya configurado desde el módulo) solo lee las carpetas: archivos, tamaño y fecha del más reciente. Pregunta quién hizo la copia y la reporta. Cada carpeta sale en verde o en rojo (vacía, falta, igual a la copia anterior o mucho más pequeña) y llega un correo. Si pasan más de `BACKUP_DIAS` sin copia, avisa (`copias_revisar.php` en el cron). También se puede registrar a mano con una captura |
| Página web | Historial de cada actualización del inventario de la tienda en línea: día, hora de inicio y fin, cuánto tardó, cuánto estuvo la página en aviso para el cliente, cuántas referencias nuevas, cambiadas y eliminadas, y desde qué equipo y usuario. Un disparador en la base de la página (`extras/web_historial_actualizaciones.sql`) guarda cada vuelta del actualizador; el módulo la lee con `WEB_DB_*` del `.env`. Filtro por mes y Excel |
| Redes WiFi | Redes y claves cifradas (solo administradores) |
| Importar Excel | Carga inicial desde .xlsx o .csv con vista previa |
| Configuración | Usuarios y permisos por módulo, bodegas, áreas, categorías, registro de cambios y **avisos por correo por persona**: qué le llega a cada uno (inventario, copias de seguridad y cada paso de las solicitudes, con opción de solo las urgentes), además de los correos fijos del `.env` |
| Avisos por correo | Cada vez que alguien ingresa o cambia un equipo desde la plataforma llega un correo con qué cambió (antes y ahora), quién y cuándo. Los destinatarios van en `MAIL_INVENTARIO` del `.env` |

## Instalar

1. Crear la base (utf8mb4) y un usuario MySQL con permisos sobre ella.
2. Copiar `.env.example` a `.env` y llenarlo. Mejor aún, dejarlo fuera de `public_html` en `private/inventarioti.env` (el sistema lo busca ahí primero).
3. Generar `APP_KEY`: `php -r "echo 'base64:' . base64_encode(random_bytes(32));"`
4. Subir la carpeta (por ejemplo a `public_html/inventarioti`).
5. Abrir `instalar.php`: crea las tablas (`schema.sql`) y el primer administrador.
6. Borrar `instalar.php` del servidor.
7. Darle escritura a `uploads/` (firmas, actas escaneadas y descargas; solo salen por `archivo.php` y `descargar.php` con sesión).

Para crear usuarios y dar permisos hace falta la marca `gestiona_usuarios`, que solo se pone por SQL:

```sql
UPDATE usuarios SET gestiona_usuarios = 1 WHERE email = 'admin@ejemplo.com';
```

## Permisos

- **Rol**: `consulta` solo ve; `admin` además edita, entrega y firma.
- **Módulos**: a cada usuario se le marcan los módulos que puede ver. Vacío = todos.
- **Usuarios y permisos**: solo los que tienen `gestiona_usuarios`. Los demás no ven esa pestaña ni su historial.
- Para quitarle el acceso a alguien se le da **Inactivar** (aplica al instante y se conserva su historial).

## Seguridad

- Sesión con timeout, cookies `HttpOnly`/`SameSite`, CSRF en todos los formularios y cabeceras CSP.
- "¿Olvidaste tu clave?": código de 6 dígitos al correo registrado, vence en 10 minutos, 5 intentos, sin revelar si el usuario existe; avisa al usuario y a `MAIL_ALERTAS` cuando se cambia.
- Bloqueo de ingreso tras 5 claves malas (por correo) o 20 (por IP) en 15 minutos, con aviso por correo.
- Claves WiFi y PINs cifrados con `APP_KEY` (AES-256-GCM).
- `.htaccess` bloquea `.env`, `.sql`, `inc/`, `uploads/` e `instalar.php`.
- Cada firma guarda imagen, nombre, cédula, fecha, IP, navegador y una huella SHA-256 del contenido.

## Estructura

```
inc/            funciones, encabezado, documento del acta
assets/         css, js y librerías locales (qrcode, jszip): la CSP no deja cargar CDNs
uploads/        archivos subidos (no van al repo)
schema.sql      estructura de la base
```

Los textos legales de las actas están al inicio de `inc/acta_documento.php`.
