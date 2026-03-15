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
    nit VARCHAR(30) NOT NULL,
    direccion VARCHAR(220) NULL,
    contacto VARCHAR(120) NULL,
    telefono VARCHAR(60) NULL,
    canal VARCHAR(80) NULL,
    regional VARCHAR(80) NULL,
    empleado_ventas VARCHAR(120) NULL,
    responsable_usuario_id BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cliente_cuenta (cuenta),
    KEY idx_cliente_regional (regional),
    KEY idx_cliente_canal (canal),
    KEY idx_cliente_nombre (nombre),
    KEY idx_cliente_responsable (responsable_usuario_id),
    CONSTRAINT fk_cliente_responsable FOREIGN KEY (responsable_usuario_id) REFERENCES usuarios(id)
);

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
    nro_ref_cliente VARCHAR(80) NULL,
    tipo VARCHAR(50) NOT NULL,
    documento_uid VARCHAR(180) NOT NULL,
    tipo_documento_financiero ENUM('factura', 'nota_credito', 'recibo', 'ajuste') NOT NULL DEFAULT 'factura',
    fecha_contabilizacion DATE NOT NULL,
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
    INDEX idx_cartera_documento_uid (documento_uid),
    INDEX idx_cartera_saldo_pendiente (saldo_pendiente),
    INDEX idx_cartera_fecha_vencimiento (fecha_vencimiento),
    INDEX idx_cartera_dias_vencido (dias_vencido),
    INDEX idx_cliente (cliente),
    INDEX idx_documento (nro_documento),
    INDEX idx_documento_uid (documento_uid),
    INDEX idx_vencimiento (fecha_vencimiento),
    INDEX idx_mora (dias_vencido),
    INDEX idx_canal (canal),
    INDEX idx_regional (regional),
    CONSTRAINT fk_doc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_doc_carga_origen FOREIGN KEY (id_carga) REFERENCES cargas_cartera(id)
);

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

CREATE TABLE IF NOT EXISTS recaudo_cargas (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    archivo VARCHAR(255) NOT NULL,
    hash_sha256 VARCHAR(64) NOT NULL UNIQUE,
    usuario VARCHAR(120) NOT NULL,
    fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    periodo_detectado VARCHAR(7) NOT NULL,
    total_registros INT NOT NULL DEFAULT 0,
    total_recaudo DECIMAL(18,2) NOT NULL DEFAULT 0,
    estado ENUM('activa', 'anulada') NOT NULL DEFAULT 'activa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recaudo_cargas_fecha (fecha_carga),
    INDEX idx_recaudo_cargas_periodo (periodo_detectado)
);

ALTER TABLE recaudo_cargas
    ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1 AFTER periodo_detectado,
    ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1 AFTER version;

ALTER TABLE recaudo_cargas
    ADD INDEX IF NOT EXISTS idx_recaudo_cargas_activo (activo);

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
    bucket VARCHAR(30) NULL,
    cartera_documento_id BIGINT NOT NULL,
    cliente_conciliado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recaudo_detalle_carga (carga_id),
    INDEX idx_recaudo_detalle_periodo (periodo),
    INDEX idx_recaudo_detalle_documento (documento_aplicado),
    CONSTRAINT fk_recaudo_detalle_carga FOREIGN KEY (carga_id) REFERENCES recaudo_cargas(id),
    CONSTRAINT fk_recaudo_detalle_documento FOREIGN KEY (cartera_documento_id) REFERENCES cartera_documentos(id)
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
    CONSTRAINT fk_recaudo_error_carga FOREIGN KEY (carga_id) REFERENCES recaudo_cargas(id)
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
    CONSTRAINT fk_recaudo_agregado_carga FOREIGN KEY (carga_id) REFERENCES recaudo_cargas(id)
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

CREATE OR REPLACE VIEW vw_cartera_documentos AS
SELECT
    d.*,
    d.uens AS uen
FROM cartera_documentos d;
