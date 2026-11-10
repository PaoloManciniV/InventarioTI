-- inventario ti: estructura de la base, sacada de la base en produccion (sin datos)
-- la usa instalar.php; se puede correr varias veces (CREATE TABLE IF NOT EXISTS)
-- sin llaves foraneas a proposito

CREATE TABLE IF NOT EXISTS `accesos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `nota` varchar(60) DEFAULT NULL,
  `ua` varchar(255) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_fecha` (`email`,`fecha`),
  KEY `idx_ip_fecha` (`ip`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `acta_archivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acta_id` int(11) NOT NULL,
  `archivo` varchar(255) NOT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_acta` (`acta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `acta_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acta_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `etiqueta` varchar(40) DEFAULT NULL,
  `descripcion` varchar(255) NOT NULL,
  `serial` varchar(120) DEFAULT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `condicion` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acta` (`acta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `actas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `consecutivo` varchar(20) NOT NULL,
  `tipo` enum('entrega','devolucion') NOT NULL,
  `prestamo` tinyint(1) NOT NULL DEFAULT 0,
  `colaborador_id` int(11) NOT NULL,
  `sede_id` int(11) DEFAULT NULL,
  `fecha` date NOT NULL,
  `observaciones` text DEFAULT NULL,
  `estado` enum('pendiente','firmada','anulada') NOT NULL DEFAULT 'pendiente',
  `historica` tinyint(1) NOT NULL DEFAULT 0,
  `aplica_inventario` tinyint(1) NOT NULL DEFAULT 1,
  `token` char(64) DEFAULT NULL,
  `token_expira` datetime DEFAULT NULL,
  `enviado_a` varchar(150) DEFAULT NULL,
  `enviado_en` datetime DEFAULT NULL,
  `firma_archivo` varchar(255) DEFAULT NULL,
  `firma_nombre` varchar(150) DEFAULT NULL,
  `firma_cedula` varchar(30) DEFAULT NULL,
  `firmado_en` datetime DEFAULT NULL,
  `firmado_modo` enum('pantalla','link','papel') DEFAULT NULL,
  `firmado_ip` varchar(45) DEFAULT NULL,
  `firmado_ua` varchar(255) DEFAULT NULL,
  `hash_contenido` char(64) DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  `anulado_motivo` varchar(255) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `consecutivo` (`consecutivo`),
  KEY `idx_colab` (`colaborador_id`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `ajustes` (
  `clave` varchar(60) NOT NULL,
  `valor` varchar(500) DEFAULT NULL,
  `actualizado` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `grupos` varchar(150) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `cambios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tabla` varchar(30) NOT NULL,
  `ref_id` int(11) NOT NULL,
  `campo` varchar(40) NOT NULL,
  `antes` text DEFAULT NULL,
  `despues` text DEFAULT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ref` (`tabla`,`ref_id`),
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `prefijo` varchar(10) NOT NULL,
  `mant_meses` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `claves_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `ref_id` int(11) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `colaboradores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `alias` varchar(500) DEFAULT NULL,
  `cedula` varchar(30) DEFAULT NULL,
  `cargo` varchar(120) DEFAULT NULL,
  `area` varchar(120) DEFAULT NULL,
  `area_id` int(11) DEFAULT NULL,
  `grupo` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `extension_pc` varchar(10) DEFAULT NULL,
  `sede_id` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `inactivado_en` datetime DEFAULT NULL,
  `inactivado_por` int(11) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cedula` (`cedula`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `consumible_cambios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `consumible` varchar(80) DEFAULT NULL,
  `contador` int(11) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `contactos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa` varchar(120) NOT NULL,
  `persona` varchar(150) DEFAULT NULL,
  `para_que` text DEFAULT NULL,
  `telefonos` varchar(500) DEFAULT NULL,
  `email` varchar(300) DEFAULT NULL,
  `web` varchar(500) DEFAULT NULL,
  `grupo` varchar(80) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `orden` int(11) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `herramientas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `categoria` varchar(80) DEFAULT NULL,
  `para_que` text DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `version` varchar(40) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `orden` int(11) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etiqueta` varchar(40) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `formato` varchar(20) DEFAULT NULL,
  `marca` varchar(80) DEFAULT NULL,
  `modelo` varchar(120) DEFAULT NULL,
  `serial` varchar(120) DEFAULT NULL,
  `sede_id` int(11) DEFAULT NULL,
  `ubicacion` varchar(150) DEFAULT NULL,
  `orden` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `mac` varchar(20) DEFAULT NULL,
  `hostname` varchar(80) DEFAULT NULL,
  `extension` varchar(10) DEFAULT NULL,
  `consumible` varchar(80) DEFAULT NULL,
  `clave_cifrada` text DEFAULT NULL,
  `mant_meses` int(11) DEFAULT NULL,
  `ultimo_mant` date DEFAULT NULL,
  `proximo_mant` date DEFAULT NULL,
  `estado` enum('disponible','asignado','instalado','prestado','mantenimiento','danado','extraviado','cedido','baja') NOT NULL DEFAULT 'disponible',
  `condicion` varchar(120) DEFAULT NULL,
  `colaborador_id` int(11) DEFAULT NULL,
  `prestado_a` int(11) DEFAULT NULL,
  `ultimo_dueno` varchar(150) DEFAULT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `especificaciones` text DEFAULT NULL,
  `valor` decimal(14,2) DEFAULT NULL,
  `fecha_compra` date DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `etiqueta` (`etiqueta`),
  KEY `idx_serial` (`serial`),
  KEY `idx_colab` (`colaborador_id`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `mantenimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `consecutivo` varchar(20) NOT NULL,
  `item_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `tipo` enum('preventivo','correctivo') NOT NULL DEFAULT 'preventivo',
  `actividades` text DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `colaborador_id` int(11) DEFAULT NULL,
  `tecnico_id` int(11) DEFAULT NULL,
  `estado` enum('pendiente','firmado','sin_firma') NOT NULL DEFAULT 'pendiente',
  `historico` tinyint(1) NOT NULL DEFAULT 0,
  `firma_archivo` varchar(255) DEFAULT NULL,
  `firma_nombre` varchar(150) DEFAULT NULL,
  `firmado_en` datetime DEFAULT NULL,
  `firmado_ip` varchar(45) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `tipo` varchar(30) NOT NULL,
  `colaborador_id` int(11) DEFAULT NULL,
  `acta_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `sedes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `usuario` varchar(40) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol` enum('admin','consulta') NOT NULL DEFAULT 'consulta',
  `permisos` varchar(500) DEFAULT NULL,
  `gestiona_usuarios` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_ingreso` datetime DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `idx_usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `wifi_redes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ssid` varchar(100) NOT NULL,
  `clave_cifrada` text DEFAULT NULL,
  `banda` varchar(40) DEFAULT NULL,
  `seguridad` varchar(40) DEFAULT NULL,
  `aps` varchar(255) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- datos iniciales
INSERT INTO categorias (nombre, prefijo, mant_meses)
SELECT * FROM (SELECT 'Computador' n, 'PC' p, 6 m UNION ALL SELECT 'Portatil', 'LAP', 6 UNION ALL SELECT 'Monitor', 'MON', NULL
  UNION ALL SELECT 'Periferico', 'PER', NULL UNION ALL SELECT 'Celular', 'CEL', NULL UNION ALL SELECT 'Telefono IP', 'TEL', NULL
  UNION ALL SELECT 'Impresora', 'IMP', NULL UNION ALL SELECT 'Red', 'RED', NULL UNION ALL SELECT 'Herramienta', 'HER', NULL
  UNION ALL SELECT 'Dotacion', 'DOT', NULL UNION ALL SELECT 'Llaves y accesos', 'LLA', NULL UNION ALL SELECT 'Mobiliario', 'MOB', NULL
  UNION ALL SELECT 'Otro', 'OTR', NULL UNION ALL SELECT 'Tablet', 'TAB', NULL UNION ALL SELECT 'Access point', 'AP', NULL
  UNION ALL SELECT 'Servidor', 'SRV', NULL UNION ALL SELECT 'Servidor virtual', 'VM', NULL UNION ALL SELECT 'Video beam', 'VB', NULL) t
WHERE NOT EXISTS (SELECT 1 FROM categorias);
