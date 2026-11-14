# Inventario TI

Inventario de equipos de sistemas con actas de entrega, préstamo y devolución firmadas en digital.
Reemplaza el Excel y las actas en papel.

PHP sin framework, JavaScript puro y MySQL/MariaDB. Sin Composer ni build: se sube la carpeta y funciona.

> Este repositorio no trae datos: ni la base, ni el `.env`, ni lo subido a `uploads/`.

## Módulos

| Módulo | Para qué |
| --- | --- |
| Vista general | Resumen: equipos por estado y por categoría, actas pendientes y últimos movimientos. Todo es clic: cada movimiento lleva al acta, a la IP o a la hoja de vida del equipo |
| Inventario | Equipos e items con estado (disponible, asignado, prestado, cedido, dañado, por ubicar…), colores por tipo en los disponibles y una nota visible (nuevo, usado, pantalla rota…) que se pone, edita o quita desde la misma lista. Cada equipo tiene hoja de vida, "quién lo ha tenido y cuánto duró" y "Ver cambios". El responsable se puede cambiar sin acta (queda en el historial) y los combos (teclado + mouse) se separan para entregar solo una parte |
| Colaboradores | Quién tiene qué, actas de cada persona, extensión MicroSIP si llama desde el PC y "recoger todo" para retiros |
| Actas y firmas | Entregas, préstamos y devoluciones. Se firman con el dedo en el celular, por enlace o por correo. La copia firmada le llega al correo a la persona y a sistemas. En un préstamo todo queda en el inventario, por mínimo que sea |
| Mantenimientos | Preventivos cada N meses y correctivos, con firma del usuario |
| Red y extensiones | Mapa completo de IPs (.1 a .255, libres en verde, reservadas en amarillo, repetidas en rojo). Una IP libre se asigna con doble clic. Directorio de extensiones (teléfono IP o MicroSIP) y servidores resaltados |
| Impresoras | Impresoras por piso, cambios de tóner y reparaciones o revisiones por fuera (proveedor, cotización, costo final y factura) |
| Informe anual | Conteo de equipos por tipo para contabilidad, exportable a Excel |
| Herramientas | Programas, portales, configuraciones y archivos de descarga. Incluye el generador de firmas de correo (PNG con QR vCard) y el de stickers de ubicaciones para impresora Zebra (Code 128 / ZPL), los dos en la web |
| Contactos de soporte y compras | Proveedores, soporte y escalamiento, con llamada y WhatsApp |
| Redes WiFi | Redes y claves cifradas (solo administradores) |
| Importar Excel | Carga inicial desde .xlsx o .csv con vista previa |
| Configuración | Usuarios y permisos por módulo, bodegas, áreas, categorías y registro de cambios |
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
