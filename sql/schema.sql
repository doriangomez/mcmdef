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
    CONSTRAINT fk_carga_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS carga_errores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    carga_id INT NOT NULL,
    fila_excel INT NOT NULL,
    campo VARCHAR(80) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_carga_error FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
);

CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cuenta VARCHAR(80) NOT NULL,
    nombre VARCHAR(180) NOT NULL,
    nit VARCHAR(30) NOT NULL,
    direccion VARCHAR(220) NULL,
    contacto VARCHAR(120) NULL,
    telefono VARCHAR(60) NULL,
    canal VARCHAR(80) NULL,
    regional VARCHAR(80) NULL,
    empleado_ventas VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cliente_cuenta (cuenta),
    KEY idx_cliente_regional (regional),
    KEY idx_cliente_canal (canal)
);

CREATE TABLE IF NOT EXISTS documentos_cartera (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nro_documento VARCHAR(80) NOT NULL,
    nro_ref_cliente VARCHAR(80) NULL,
    tipo VARCHAR(50) NOT NULL,
    fecha_contabilizacion DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    valor_documento DECIMAL(16,2) NOT NULL,
    saldo_pendiente DECIMAL(16,2) NOT NULL,
    moneda VARCHAR(12) NOT NULL,
    dias_vencido INT NOT NULL DEFAULT 0,
    bucket_actual DECIMAL(16,2) NOT NULL DEFAULT 0,
    bucket_1_30 DECIMAL(16,2) NOT NULL DEFAULT 0,
    bucket_31_60 DECIMAL(16,2) NOT NULL DEFAULT 0,
    bucket_61_90 DECIMAL(16,2) NOT NULL DEFAULT 0,
    bucket_91_180 DECIMAL(16,2) NOT NULL DEFAULT 0,
    bucket_181_360 DECIMAL(16,2) NOT NULL DEFAULT 0,
    bucket_361_plus DECIMAL(16,2) NOT NULL DEFAULT 0,
    carga_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doc_cartera (cliente_id, nro_documento, tipo, fecha_contabilizacion),
    KEY idx_doc_vencido (dias_vencido),
    CONSTRAINT fk_doc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_doc_carga_origen FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
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
    CONSTRAINT fk_g_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_g_documento FOREIGN KEY (documento_id) REFERENCES documentos_cartera(id),
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
    CONSTRAINT fk_aud_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

INSERT INTO usuarios (nombre, email, password_hash, rol, estado, created_at, updated_at)
VALUES ('Administrador', 'admin@mcm.local', '$2y$12$1SP93VJzAK3J1GyHpsCLZOlZhFc2iklaH2HDVv/2Z9IYIWD3eBZya', 'admin', 'activo', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
