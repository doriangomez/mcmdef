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
    estado ENUM('activa', 'anulada') NOT NULL DEFAULT 'activa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cargas_fecha (fecha_carga),
    INDEX idx_cargas_estado (estado),
    CONSTRAINT fk_carga_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

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
    INDEX idx_cartera_saldo_pendiente (saldo_pendiente),
    INDEX idx_cartera_fecha_vencimiento (fecha_vencimiento),
    INDEX idx_cartera_dias_vencido (dias_vencido),
    INDEX idx_cliente (cliente),
    INDEX idx_documento (nro_documento),
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
