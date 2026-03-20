CREATE DATABASE IF NOT EXISTS mcm_cartera CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mcm_cartera;

CREATE TABLE IF NOT EXISTS usuarios (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('admin', 'analista', 'visualizador') NOT NULL DEFAULT 'visualizador',
    estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cargas_cartera (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id BIGINT NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    total_documentos INT NOT NULL DEFAULT 0,
    total_saldo DECIMAL(18,2) NOT NULL DEFAULT 0,
    hash_archivo VARCHAR(64) NOT NULL UNIQUE,
    hash_sha256 VARCHAR(64) NULL,
    periodo_detectado VARCHAR(7) NULL,
    version INT NOT NULL DEFAULT 1,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    estado ENUM('activa', 'anulada') NOT NULL DEFAULT 'activa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cargas_fecha (fecha_carga),
    INDEX idx_cargas_estado (estado),
    INDEX idx_cargas_periodo (periodo_detectado),
    INDEX idx_cargas_activo (activo),
    CONSTRAINT fk_carga_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

ALTER TABLE cargas_cartera
    ADD COLUMN IF NOT EXISTS hash_sha256 VARCHAR(64) NULL AFTER hash_archivo,
    ADD COLUMN IF NOT EXISTS periodo_detectado VARCHAR(7) NULL AFTER hash_sha256,
    ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1 AFTER periodo_detectado,
    ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1 AFTER version;

ALTER TABLE cargas_cartera
    ADD INDEX IF NOT EXISTS idx_cargas_periodo (periodo_detectado),
    ADD INDEX IF NOT EXISTS idx_cargas_activo (activo);

CREATE TABLE IF NOT EXISTS carga_errores (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    carga_id BIGINT NOT NULL,
    fila_excel INT NOT NULL,
    campo VARCHAR(80) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_carga_error FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
);

CREATE TABLE IF NOT EXISTS clientes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cuenta VARCHAR(80) NOT NULL,
    nombre VARCHAR(180) NOT NULL,
    nombre_cliente VARCHAR(180) NULL,
    nit VARCHAR(30) NOT NULL,
    nro_identificacion VARCHAR(30) NULL,
    fecha_activacion DATE NULL,
    fecha_creacion DATETIME NULL,
    estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
    nombre_normalizado VARCHAR(190) NULL,
    direccion VARCHAR(220) NULL,
    contacto VARCHAR(120) NULL,
    telefono VARCHAR(60) NULL,
    canal VARCHAR(80) NULL,
    uen VARCHAR(120) NULL,
    regional VARCHAR(80) NULL,
    empleado_ventas VARCHAR(120) NULL,
    responsable_usuario_id BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cliente_cuenta (cuenta),
    KEY idx_cliente_regional (regional),
    KEY idx_cliente_canal (canal),
    KEY idx_cliente_nombre (nombre),
    KEY idx_cliente_nombre_normalizado (nombre_normalizado),
    KEY idx_cliente_identificacion (nro_identificacion),
    KEY idx_cliente_responsable (responsable_usuario_id),
    CONSTRAINT fk_cliente_responsable FOREIGN KEY (responsable_usuario_id) REFERENCES usuarios(id)
);

ALTER TABLE clientes
    ADD COLUMN IF NOT EXISTS nombre_cliente VARCHAR(180) NULL AFTER nombre,
    ADD COLUMN IF NOT EXISTS nro_identificacion VARCHAR(30) NULL AFTER nit,
    ADD COLUMN IF NOT EXISTS fecha_activacion DATE NULL AFTER nro_identificacion,
    ADD COLUMN IF NOT EXISTS fecha_creacion DATETIME NULL AFTER fecha_activacion,
    ADD COLUMN IF NOT EXISTS estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo' AFTER fecha_creacion,
    ADD COLUMN IF NOT EXISTS nombre_normalizado VARCHAR(190) NULL AFTER estado,
    ADD COLUMN IF NOT EXISTS uen VARCHAR(120) NULL AFTER canal;

ALTER TABLE clientes
    ADD INDEX IF NOT EXISTS idx_cliente_nombre_normalizado (nombre_normalizado),
    ADD INDEX IF NOT EXISTS idx_cliente_identificacion (nro_identificacion);

UPDATE clientes
SET nombre_cliente = COALESCE(NULLIF(nombre_cliente, ''), nombre),
    nro_identificacion = COALESCE(NULLIF(nro_identificacion, ''), nit),
    fecha_activacion = COALESCE(fecha_activacion, DATE(created_at), CURDATE()),
    fecha_creacion = COALESCE(fecha_creacion, created_at, NOW()),
    estado = COALESCE(NULLIF(estado, ''), 'activo'),
    nombre_normalizado = COALESCE(NULLIF(nombre_normalizado, ''), LOWER(TRIM(nombre)));

CREATE TABLE IF NOT EXISTS cartera_documentos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_carga BIGINT NOT NULL,
    cliente_id BIGINT NOT NULL,
    cuenta VARCHAR(80) NOT NULL,
    cliente VARCHAR(180) NOT NULL,
    canal VARCHAR(80) NULL,
    uens VARCHAR(120) NULL,
    regional VARCHAR(80) NULL,
    nro_documento VARCHAR(80) NOT NULL,
    nro_ref_cliente VARCHAR(255) NULL,
    tipo VARCHAR(50) NOT NULL,
    documento_uid VARCHAR(180) NOT NULL,
    tipo_documento_financiero ENUM('factura', 'nota_credito', 'recibo', 'ajuste') NOT NULL DEFAULT 'factura',
    fecha_contabilizacion DATE NOT NULL,
    periodo VARCHAR(7) NULL,
    fecha_vencimiento DATE NOT NULL,
    valor_documento DECIMAL(18,2) NOT NULL,
    saldo_pendiente DECIMAL(18,2) NOT NULL,
    moneda VARCHAR(12) NOT NULL,
    dias_vencido INT NOT NULL DEFAULT 0,
    bucket_actual DECIMAL(18,2) NOT NULL DEFAULT 0,
    bucket_1_30 DECIMAL(18,2) NOT NULL DEFAULT 0,
    bucket_31_60 DECIMAL(18,2) NOT NULL DEFAULT 0,
    bucket_61_90 DECIMAL(18,2) NOT NULL DEFAULT 0,
    bucket_91_180 DECIMAL(18,2) NOT NULL DEFAULT 0,
    bucket_181_360 DECIMAL(18,2) NOT NULL DEFAULT 0,
    bucket_361_plus DECIMAL(18,2) NOT NULL DEFAULT 0,
    estado_documento ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
    estado_documento_detalle VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cartera_carga (id_carga),
    INDEX idx_cartera_cuenta (cuenta),
    INDEX idx_cartera_nro_documento (nro_documento),
    INDEX idx_documento_uid (documento_uid),
    INDEX idx_vencimiento (fecha_vencimiento),
    INDEX idx_periodo (periodo),
    INDEX idx_mora (dias_vencido),
    INDEX idx_cartera_saldo_pendiente (saldo_pendiente),
    INDEX idx_cliente (cliente),
    INDEX idx_canal (canal),
    INDEX idx_regional (regional),
    CONSTRAINT fk_doc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_doc_carga_origen FOREIGN KEY (id_carga) REFERENCES cargas_cartera(id)
);


ALTER TABLE cartera_documentos
    DROP INDEX IF EXISTS idx_cartera_documento_uid,
    DROP INDEX IF EXISTS idx_cartera_fecha_vencimiento,
    DROP INDEX IF EXISTS idx_cartera_dias_vencido,
    DROP INDEX IF EXISTS idx_documento,
    MODIFY COLUMN nro_ref_cliente VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS bitacora_gestion (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_documento BIGINT NOT NULL,
    usuario_id BIGINT NOT NULL,
    tipo_gestion VARCHAR(100) NOT NULL,
    observacion TEXT NOT NULL,
    compromiso_pago DATE NULL,
    valor_compromiso DECIMAL(18,2) NULL,
    estado_compromiso ENUM('pendiente', 'cumplido', 'incumplido') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bitacora_documento (id_documento),
    INDEX idx_bitacora_created_at (created_at),
    CONSTRAINT fk_bg_documento FOREIGN KEY (id_documento) REFERENCES cartera_documentos(id),
    CONSTRAINT fk_bg_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS auditoria_sistema (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT NOT NULL,
    accion VARCHAR(100) NOT NULL,
    modulo VARCHAR(100) NOT NULL,
    detalle TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auditoria_usuario (usuario_id),
    INDEX idx_auditoria_created_at (created_at),
    CONSTRAINT fk_aud_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS cliente_historial (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT NOT NULL,
    fecha_evento DATETIME NOT NULL,
    tipo_evento VARCHAR(30) NOT NULL,
    valor DECIMAL(18,2) NOT NULL DEFAULT 0,
    descripcion TEXT NOT NULL,
    documento_id BIGINT NULL,
    carga_id BIGINT NULL,
    recaudo_detalle_id BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cliente_historial_cliente_fecha (cliente_id, fecha_evento),
    INDEX idx_cliente_historial_tipo (tipo_evento),
    CONSTRAINT fk_cliente_historial_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_cliente_historial_documento FOREIGN KEY (documento_id) REFERENCES cartera_documentos(id),
    CONSTRAINT fk_cliente_historial_carga FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
);


CREATE TABLE IF NOT EXISTS system_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO usuarios (nombre, email, password_hash, rol, estado, created_at, updated_at)
VALUES ('Administrador', 'admin@mcm.local', '$2y$12$1SP93VJzAK3J1GyHpsCLZOlZhFc2iklaH2HDVv/2Z9IYIWD3eBZya', 'admin', 'activo', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

CREATE TABLE IF NOT EXISTS cargas_recaudo (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    archivo VARCHAR(255) NOT NULL,
    hash_sha256 VARCHAR(64) NOT NULL UNIQUE,
    usuario_id BIGINT NOT NULL,
    fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    periodo VARCHAR(7) NOT NULL,
    total_registros INT NOT NULL DEFAULT 0,
    total_recaudo DECIMAL(18,2) NOT NULL DEFAULT 0,
    estado ENUM('activa', 'anulada') NOT NULL DEFAULT 'activa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recaudo_cargas_fecha (fecha_carga),
    INDEX idx_recaudo_cargas_periodo (periodo),
    CONSTRAINT fk_cargas_recaudo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

ALTER TABLE cargas_recaudo
    ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1 AFTER periodo,
    ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1 AFTER version;

ALTER TABLE cargas_recaudo
    ADD INDEX IF NOT EXISTS idx_recaudo_cargas_activo (activo),
    ADD INDEX IF NOT EXISTS idx_recaudo_cargas_estado_activo (estado, activo);


CREATE TABLE IF NOT EXISTS periodos_cartera (
    periodo VARCHAR(7) PRIMARY KEY,
    cartera_cargada TINYINT(1) NOT NULL DEFAULT 0,
    recaudo_cargado TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS recaudo_detalle (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    carga_id BIGINT NOT NULL,
    nro_recibo VARCHAR(80) NOT NULL,
    fecha_recibo DATE NULL,
    fecha_aplicacion DATE NULL,
    documento_aplicado VARCHAR(80) NOT NULL,
    tipo_documento VARCHAR(50) NULL,
    cliente VARCHAR(180) NULL,
    vendedor VARCHAR(120) NULL,
    importe_aplicado DECIMAL(18,2) NOT NULL,
    saldo_documento DECIMAL(18,2) NOT NULL DEFAULT 0,
    periodo VARCHAR(7) NOT NULL,
    uen VARCHAR(120) NULL,
    canal VARCHAR(80) NULL,
    regional VARCHAR(80) NULL,
    bucket VARCHAR(30) NULL,
    cartera_documento_id BIGINT NULL,
    cliente_conciliado TINYINT(1) NOT NULL DEFAULT 1,
    estado_conciliacion VARCHAR(40) NULL,
    observacion_conciliacion VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recaudo_detalle_carga (carga_id),
    INDEX idx_recaudo_detalle_periodo (periodo),
    INDEX idx_recaudo_detalle_documento (documento_aplicado),
    CONSTRAINT fk_recaudo_detalle_carga FOREIGN KEY (carga_id) REFERENCES cargas_recaudo(id),
    CONSTRAINT fk_recaudo_detalle_documento FOREIGN KEY (cartera_documento_id) REFERENCES cartera_documentos(id)
);

ALTER TABLE recaudo_detalle
    ADD COLUMN IF NOT EXISTS regional VARCHAR(80) NULL AFTER canal,
    MODIFY COLUMN cartera_documento_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS estado_conciliacion VARCHAR(40) NULL AFTER cliente_conciliado,
    ADD COLUMN IF NOT EXISTS observacion_conciliacion VARCHAR(255) NULL AFTER estado_conciliacion;

CREATE TABLE IF NOT EXISTS conciliacion_cartera_recaudo (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_recaudo_detalle BIGINT NULL,
    id_cartera_documento BIGINT NULL,
    id_carga_recaudo BIGINT NOT NULL,
    estado ENUM('conciliado_total','conciliado_parcial','pago_excedido','sin_pago','pago_sin_factura','tipo_no_coincide','periodo_diferente') NOT NULL,
    importe_aplicado DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_pendiente_cartera DECIMAL(18,2) NOT NULL DEFAULT 0,
    diferencia DECIMAL(18,2) NOT NULL DEFAULT 0,
    fecha_conciliacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observacion TEXT NULL,
    periodo_cartera VARCHAR(7) NULL,
    periodo_recaudo VARCHAR(7) NULL,
    cartera_id BIGINT NULL,
    recaudo_id BIGINT NOT NULL,
    numero_documento VARCHAR(80) NOT NULL,
    cliente_cartera VARCHAR(180) NULL,
    cliente_recaudo VARCHAR(180) NULL,
    valor_factura DECIMAL(18,2) NOT NULL DEFAULT 0,
    valor_pagado DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_resultante DECIMAL(18,2) NOT NULL DEFAULT 0,
    estado_conciliacion ENUM('conciliado_total','conciliado_parcial','sin_pago','pago_sin_factura','pago_excedido','periodo_diferente','tipo_no_coincide') NOT NULL,
    nivel_confianza INT NOT NULL DEFAULT 100,
    detalle_validacion TEXT NULL,
    INDEX idx_conciliacion_carga_recaudo (id_carga_recaudo),
    INDEX idx_conciliacion_cartera_documento (id_cartera_documento),
    INDEX idx_conciliacion_estado_nuevo (estado),
    INDEX idx_conciliacion_recaudo_id (recaudo_id),
    INDEX idx_conciliacion_documento (numero_documento),
    INDEX idx_conciliacion_estado (estado_conciliacion),
    INDEX idx_conciliacion_periodo (periodo_cartera, periodo_recaudo),
    CONSTRAINT fk_conciliacion_recaudo FOREIGN KEY (recaudo_id) REFERENCES cargas_recaudo(id),
    CONSTRAINT fk_conciliacion_cartera FOREIGN KEY (cartera_id) REFERENCES cartera_documentos(id),
    CONSTRAINT fk_conciliacion_recaudo_detalle FOREIGN KEY (id_recaudo_detalle) REFERENCES recaudo_detalle(id),
    CONSTRAINT fk_conciliacion_cartera_documento FOREIGN KEY (id_cartera_documento) REFERENCES cartera_documentos(id),
    CONSTRAINT fk_conciliacion_carga_recaudo FOREIGN KEY (id_carga_recaudo) REFERENCES cargas_recaudo(id)
);


CREATE TABLE IF NOT EXISTS recaudo_validacion_errores (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    carga_id BIGINT NOT NULL,
    fila INT NOT NULL,
    campo VARCHAR(100) NOT NULL,
    valor VARCHAR(255) NULL,
    motivo VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recaudo_errores_carga (carga_id),
    CONSTRAINT fk_recaudo_error_carga FOREIGN KEY (carga_id) REFERENCES cargas_recaudo(id)
);

CREATE TABLE IF NOT EXISTS recaudo_agregados (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    carga_id BIGINT NOT NULL,
    periodo VARCHAR(7) NOT NULL,
    tipo_agregado ENUM('total', 'vendedor', 'cliente', 'uen') NOT NULL,
    clave VARCHAR(180) NOT NULL,
    valor_recaudo DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_recaudo_agregado (carga_id, tipo_agregado, clave),
    INDEX idx_recaudo_agregado_periodo (periodo),
    CONSTRAINT fk_recaudo_agregado_carga FOREIGN KEY (carga_id) REFERENCES cargas_recaudo(id)
);

CREATE TABLE IF NOT EXISTS presupuesto_recaudo (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    periodo VARCHAR(7) NOT NULL,
    vendedor VARCHAR(120) NOT NULL,
    valor_presupuesto DECIMAL(18,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_presupuesto_recaudo (periodo, vendedor)
);

CREATE TABLE IF NOT EXISTS control_periodos_cartera (
    id INT AUTO_INCREMENT PRIMARY KEY,
    periodo VARCHAR(7) NOT NULL,
    cartera_cargada TINYINT(1) NOT NULL DEFAULT 0,
    recaudo_cargado TINYINT(1) NOT NULL DEFAULT 0,
    presupuesto_cargado TINYINT(1) NOT NULL DEFAULT 0,
    periodo_activo TINYINT(1) NOT NULL DEFAULT 0,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'abierto',
    UNIQUE KEY uk_control_periodo (periodo),
    KEY idx_control_periodo_activo (periodo_activo)
);

CREATE OR REPLACE VIEW vw_cartera_documentos AS
SELECT
    d.*,
    TRIM(COALESCE(d.uens, "")) AS uen
FROM cartera_documentos d;
