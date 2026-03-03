CREATE DATABASE IF NOT EXISTS mcm_cartera CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mcm_cartera;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('admin', 'analista', 'visualizador') NOT NULL DEFAULT 'visualizador',
    estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cargas_cartera (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NOT NULL,
    hash_archivo VARCHAR(64) NOT NULL UNIQUE,
    usuario_id INT NOT NULL,
    fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_registros INT NOT NULL DEFAULT 0,
    total_errores INT NOT NULL DEFAULT 0,
    total_nuevos INT NOT NULL DEFAULT 0,
    total_actualizados INT NOT NULL DEFAULT 0,
    estado ENUM('procesado', 'con_errores', 'revertida') NOT NULL DEFAULT 'con_errores',
    observaciones TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_carga_fecha (fecha_carga),
    KEY idx_carga_estado (estado),
    CONSTRAINT fk_carga_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS carga_errores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    carga_id INT NOT NULL,
    fila_excel INT NOT NULL,
    campo VARCHAR(80) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_error_carga (carga_id),
    CONSTRAINT fk_carga_error FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
);

CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nit VARCHAR(30) NOT NULL,
    nombre VARCHAR(180) NOT NULL,
    canal VARCHAR(80) NULL,
    regional VARCHAR(80) NULL,
    asesor_comercial VARCHAR(120) NULL,
    ejecutivo_cartera VARCHAR(120) NULL,
    uen VARCHAR(120) NULL,
    marca VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nit (nit),
    KEY idx_cliente_nit (nit),
    KEY idx_cliente_canal (canal),
    KEY idx_cliente_regional (regional),
    KEY idx_cliente_uen (uen),
    KEY idx_cliente_marca (marca)
);

CREATE TABLE IF NOT EXISTS documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    tipo_documento ENUM('Factura', 'NC') NOT NULL,
    numero_documento VARCHAR(80) NOT NULL,
    fecha_emision DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    valor_original DECIMAL(16,2) NOT NULL,
    saldo_actual DECIMAL(16,2) NOT NULL,
    dias_mora INT NOT NULL DEFAULT 0,
    periodo VARCHAR(20) NULL,
    estado_documento ENUM('vigente', 'vencido', 'cancelado') NOT NULL,
    id_carga_origen INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doc (cliente_id, tipo_documento, numero_documento),
    KEY idx_doc_periodo (periodo),
    KEY idx_doc_mora (dias_mora),
    KEY idx_doc_estado (estado_documento),
    KEY idx_doc_origen (id_carga_origen),
    CONSTRAINT fk_doc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_doc_carga_origen FOREIGN KEY (id_carga_origen) REFERENCES cargas_cartera(id)
);

CREATE TABLE IF NOT EXISTS documentos_snapshot (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    carga_id INT NOT NULL,
    nit VARCHAR(30) NOT NULL,
    nombre_cliente VARCHAR(180) NOT NULL,
    tipo_documento ENUM('Factura', 'NC') NOT NULL,
    numero_documento VARCHAR(80) NOT NULL,
    fecha_emision DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    valor_original DECIMAL(16,2) NOT NULL,
    saldo_actual DECIMAL(16,2) NOT NULL,
    dias_mora INT NOT NULL DEFAULT 0,
    periodo VARCHAR(20) NULL,
    canal VARCHAR(80) NULL,
    regional VARCHAR(80) NULL,
    asesor_comercial VARCHAR(120) NULL,
    ejecutivo_cartera VARCHAR(120) NULL,
    uen VARCHAR(120) NULL,
    marca VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_snapshot_row (carga_id, nit, tipo_documento, numero_documento),
    KEY idx_snapshot_lookup (nit, tipo_documento, numero_documento, carga_id),
    CONSTRAINT fk_snapshot_carga FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
);

CREATE TABLE IF NOT EXISTS gestiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NULL,
    documento_id INT NULL,
    tipo_gestion VARCHAR(80) NOT NULL,
    descripcion TEXT NOT NULL,
    fecha_compromiso DATE NULL,
    valor_compromiso DECIMAL(16,2) NULL,
    estado_compromiso ENUM('pendiente', 'cumplido', 'incumplido') NULL,
    usuario_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anulada TINYINT(1) NOT NULL DEFAULT 0,
    motivo_anulacion VARCHAR(255) NULL,
    KEY idx_gestion_tipo (tipo_gestion),
    KEY idx_gestion_estado (estado_compromiso),
    KEY idx_gestion_created (created_at),
    CONSTRAINT fk_g_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_g_documento FOREIGN KEY (documento_id) REFERENCES documentos(id),
    CONSTRAINT fk_g_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS auditoria_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tabla VARCHAR(80) NOT NULL,
    registro_id INT NOT NULL,
    campo VARCHAR(80) NOT NULL,
    valor_anterior TEXT NULL,
    valor_nuevo TEXT NULL,
    usuario_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aud_tabla (tabla),
    KEY idx_aud_usuario (usuario_id),
    KEY idx_aud_fecha (created_at),
    CONSTRAINT fk_aud_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

INSERT INTO usuarios (nombre, email, password_hash, rol, estado, created_at, updated_at)
VALUES ('Administrador', 'admin@mcm.local', '$2y$12$1SP93VJzAK3J1GyHpsCLZOlZhFc2iklaH2HDVv/2Z9IYIWD3eBZya', 'admin', 'activo', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
