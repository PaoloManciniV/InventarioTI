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
CREATE TABLE IF NOT EXISTS `copias_seguridad` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL,
  `persona` varchar(120) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `origen` enum('script','manual') NOT NULL DEFAULT 'script',
  `pc` varchar(80) DEFAULT NULL,
  `usuario_windows` varchar(80) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `disco` text DEFAULT NULL,
  `carpetas` mediumtext DEFAULT NULL,
  `total_mb` decimal(14,2) DEFAULT NULL,
  `estado` enum('ok','revisar') NOT NULL DEFAULT 'ok',
  `alertas` text DEFAULT NULL,
  `captura` varchar(255) DEFAULT NULL,
  `nota` text DEFAULT NULL,
  `registrado_por` int(11) DEFAULT NULL,
  `creado` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_ip_creado` (`ip`,`creado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- tareas del equipo de sistemas
CREATE TABLE IF NOT EXISTS tareas_tipos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria VARCHAR(80) NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tipo (categoria, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tareas_tipos (categoria, nombre, orden) VALUES
('Soporte a usuarios','Soporte en sitio a un usuario',10),
('Soporte a usuarios','Soporte remoto a un usuario',20),
('Soporte a usuarios','Restablecer contraseña o desbloquear cuenta',30),
('Soporte a usuarios','Configurar correo (Zoho / Outlook)',40),
('Soporte a usuarios','Instalar o actualizar un programa',50),
('Soporte a usuarios','Office y licencias',60),
('Soporte a usuarios','Falla de internet o red de un usuario',70),
('Equipos','Alistar equipo nuevo (formateo e instalación)',80),
('Equipos','Formatear o reinstalar Windows',90),
('Equipos','Mantenimiento preventivo',100),
('Equipos','Mantenimiento correctivo o reparación',110),
('Equipos','Entregar equipo con acta',120),
('Equipos','Recoger equipo (devolución o retiro)',130),
('Equipos','Revisar equipo dañado',140),
('Equipos','Cambiar periférico (mouse, teclado, monitor)',150),
('Equipos','Actualizar el inventario TI',160),
('Impresoras','Cambio de tóner',170),
('Impresoras','Atasco o falla de impresión',180),
('Impresoras','Instalar impresora en un PC',190),
('Impresoras','Mandar impresora a reparar',200),
('Impresoras','Impresora Zebra o etiquetas',210),
('Telefonía','Configurar teléfono IP o extensión',220),
('Telefonía','Configurar MicroSIP',230),
('Telefonía','Revisar la PBX o troncales',240),
('Telefonía','Falla de llamadas',250),
('Red e infraestructura','Revisar internet o proveedor',260),
('Red e infraestructura','Access point o WiFi',270),
('Red e infraestructura','Switch o cableado',280),
('Red e infraestructura','Asignar o revisar una IP',290),
('Red e infraestructura','Cámaras o DVR',300),
('Red e infraestructura','Revisar servidor',310),
('Traverse y sistemas internos','Soporte en Traverse (ERP)',320),
('Traverse y sistemas internos','Usuarios y permisos en Traverse',330),
('Traverse y sistemas internos','Consulta o reporte en SQL',340),
('Traverse y sistemas internos','Error en un sistema interno (web)',350),
('Traverse y sistemas internos','Desarrollo o mejora de un sistema interno',360),
('Traverse y sistemas internos','Automatización (n8n)',370),
('Traverse y sistemas internos','Página web (WordPress)',380),
('Respaldo y seguridad','Copia de seguridad al disco externo',390),
('Respaldo y seguridad','Antivirus o amenaza',400),
('Respaldo y seguridad','Correo sospechoso (phishing)',410),
('Respaldo y seguridad','Ingreso de personal: crear accesos',420),
('Respaldo y seguridad','Retiro de personal: quitar accesos',430),
('Compras y administrativo','Cotizar o comprar equipos o insumos',440),
('Compras y administrativo','Seguimiento a proveedor o garantía',450),
('Compras y administrativo','Reunión o capacitación',460),
('Compras y administrativo','Documentar un procedimiento',470),
('Compras y administrativo','Otra (explicar en la observación)',480);

CREATE TABLE IF NOT EXISTS tareas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo_id INT NULL,
  titulo VARCHAR(200) NOT NULL COMMENT 'nombre del tipo al crearla',
  detalle TEXT NULL COMMENT 'observacion',
  colaborador_id INT NULL,
  item_id INT NULL,
  creado_por INT NOT NULL,
  para_id INT NULL COMMENT 'NULL = todo el grupo',
  privada TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = solo la ve quien la creo',
  vence DATETIME NULL,
  con_hora TINYINT(1) NOT NULL DEFAULT 0,
  prioridad ENUM('normal','alta') NOT NULL DEFAULT 'normal',
  hecha TINYINT(1) NOT NULL DEFAULT 0,
  hecha_por INT NULL,
  hecha_en DATETIME NULL,
  aviso_vencida DATETIME NULL,
  ultimo_recordatorio DATETIME NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NULL,
  KEY idx_pendientes (hecha, vence),
  KEY idx_para (para_id),
  KEY idx_creado_por (creado_por),
  KEY idx_tipo (tipo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- notas del caso: lo que cada uno fue haciendo, transferencias y cambios (para saber hasta donde llego el otro)
CREATE TABLE IF NOT EXISTS tareas_notas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarea_id INT NOT NULL,
  usuario_id INT NULL,
  tipo ENUM('nota','transferencia','evento') NOT NULL DEFAULT 'nota',
  texto TEXT NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tarea (tarea_id, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- bloc de notas personal (stickies): solo los ve su dueño
CREATE TABLE IF NOT EXISTS notas_personales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  texto TEXT NULL,
  color VARCHAR(10) NOT NULL DEFAULT 'amarillo',
  fijada TINYINT(1) NOT NULL DEFAULT 0,
  ancho SMALLINT NULL,
  alto SMALLINT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NULL,
  KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- solicitudes a administracion
CREATE TABLE IF NOT EXISTS solicitudes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL COMMENT 'cuando se le entrego el formato a la administradora',
  solicitado_por INT NOT NULL,
  motivo TEXT NULL,
  urgencia ENUM('normal','urgente') NOT NULL DEFAULT 'normal',
  colaborador_id INT NULL COMMENT 'para quien es (opcional)',
  item_id INT NULL COMMENT 'equipo relacionado (opcional)',
  estado ENUM('por_aprobar','aprobada','pendiente','en_compra','recibida','rechazada','cancelada') NOT NULL DEFAULT 'por_aprobar',
  fecha_estado DATETIME NULL,
  fecha_recibida DATE NULL,
  costo_total DECIMAL(14,2) NULL,
  formato_archivo VARCHAR(255) NULL COMMENT 'foto o escaneo del formato en papel',
  factura_archivo VARCHAR(255) NULL,
  aviso_atraso DATE NULL,
  tipo_compra VARCHAR(20) NOT NULL DEFAULT 'consumibles' COMMENT 'herramientas, repuestos, consumibles, otros',
  trabajo_realizado VARCHAR(255) NULL,
  token CHAR(10) NULL COMMENT 'codigo corto impreso en el PDF',
  aprobado_por INT NULL,
  aprobado_en DATETIME NULL,
  rechazo_motivo TEXT NULL,
  impreso_por INT NULL,
  impreso_en DATETIME NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NULL,
  KEY idx_estado (estado, fecha),
  KEY idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS solicitudes_lineas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  solicitud_id INT NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  descripcion VARCHAR(200) NOT NULL,
  cantidad INT NOT NULL DEFAULT 1,
  unidad VARCHAR(30) NULL,
  especificaciones VARCHAR(255) NULL,
  costo_estimado DECIMAL(14,2) NULL,
  KEY idx_solicitud (solicitud_id),
  KEY idx_desc (descripcion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- lo que fue pasando con cada solicitud (creada, cambio de estado, notas)
CREATE TABLE IF NOT EXISTS solicitudes_eventos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  solicitud_id INT NOT NULL,
  usuario_id INT NULL,
  texto TEXT NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_solicitud (solicitud_id, creado)
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
CREATE TABLE IF NOT EXISTS `reparaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'reparacion',
  `fecha_envio` date NOT NULL,
  `proveedor` varchar(150) NOT NULL,
  `falla` text DEFAULT NULL,
  `cotizacion_valor` decimal(14,2) DEFAULT NULL,
  `cotizacion_archivo` varchar(255) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'enviada',
  `estado_antes` varchar(20) DEFAULT NULL,
  `fecha_regreso` date DEFAULT NULL,
  `trabajo` text DEFAULT NULL,
  `costo_final` decimal(14,2) DEFAULT NULL,
  `factura_archivo` varchar(255) DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
