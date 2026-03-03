# Manual técnico (MVP Fase 1)

## 1) Requisitos
- PHP 8.x con extensión PDO MySQL.
- MySQL 8.x o MariaDB.
- Servidor web apuntando a `public/`.
- Importación XLSX/XLS mediante `app/libraries/SimpleXLSX.php` (sin dependencias externas).

> La importación XLSX/XLS funciona sin Composer usando SimpleXLSX embebido.

## 2) Instalación y configuración
1. Ejecutar `sql/schema.sql` en MySQL.
2. Definir variables de entorno:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
   - `APP_BASE_URL` (opcional, ej: `/mcmdef/public`)
3. Publicar carpeta `public/` como document root.
4. Ingresar a `/login.php`.

Usuario inicial:
- Email: `admin@mcm.local`
- Password objetivo: `Admin123*`

## 3) Arquitectura y carpetas
- `public/`: entry points y rutas del sistema.
- `app/config`: conexión DB y sesión/autenticación.
- `app/middlewares`: protección por login y rol.
- `app/services`: importación, exportación, auditoría.
- `app/views`: layout y navbar.
- `sql/schema.sql`: modelo de datos.

## 4) Módulos y rutas implementadas
- Dashboard: `/index.php`
- Login: `/login.php`
- Cargas:
  - `/cargas/nueva.php`
  - `/cargas/historial.php`
  - `/cargas/detalle.php?id=...`
- Cartera:
  - `/cartera/lista.php`
  - `/cartera/cliente.php?id_cliente=...`
  - `/cartera/documento.php?id_documento=...`
- Gestión:
  - `/gestion/nueva.php`
  - `/gestion/lista.php`
- Reportes: `/reportes/index.php`
- Administración:
  - `/admin/usuarios.php`
  - `/admin/auditoria.php`

## 5) Roles y permisos MVP
- **Admin**:
  - usuarios (crear, activar/inactivar, reset password),
  - cargas (incluye reversión de última carga),
  - auditoría completa,
  - exportaciones.
- **Analista**:
  - consultar cartera, filtros, gestión/compromisos,
  - cargas (sin reversión),
  - exportación de cartera/reportes.
- **Visualizador**:
  - consulta y filtros de cartera/reportes,
  - sin exportaciones operativas.

## 6) Modelo de datos (resumen)
Tablas principales:
- `usuarios`
- `cargas_cartera`
- `carga_errores`
- `clientes`
- `documentos` (con `id_carga_origen`)
- `documentos_snapshot` (histórico por carga para reversión)
- `gestiones`
- `auditoria_log`

## 7) Importador SAP (reglas críticas)
### 7.1 Plantilla exacta esperada (orden obligatorio)
`nit,nombre_cliente,tipo_documento,numero_documento,fecha_emision,fecha_vencimiento,valor_original,saldo_actual,dias_mora,periodo,canal,regional,asesor_comercial,ejecutivo_cartera,uen,marca`

### 7.2 Validaciones bloqueantes
- Archivo vacío.
- Columnas distintas al orden esperado.
- Campos críticos vacíos:
  - `nit`, `nombre_cliente`, `tipo_documento`, `numero_documento`,
  - `fecha_emision`, `fecha_vencimiento`, `saldo_actual`.
- Tipo de documento no válido (solo `Factura` o `NC`).
- Fechas inválidas o `fecha_emision > fecha_vencimiento`.
- Valores numéricos inválidos (`valor_original`, `saldo_actual`, `dias_mora`).
- Duplicados en archivo por clave `(nit + tipo_documento + numero_documento)`.
- Archivo ya cargado (hash SHA-256 repetido).
- Inconsistencia por duplicados múltiples en BD (si existiera legacy fuera de índice único).

### 7.3 Regla de clave única
- Clave de negocio: `nit + tipo_documento + numero_documento`.
- En tabla viva `documentos`, el índice único es `(cliente_id, tipo_documento, numero_documento)` y `cliente_id` se deriva de `nit`.

### 7.4 Días de mora
- Si `dias_mora` viene en archivo, se utiliza.
- Si viene vacío, se calcula:
  - `max(0, hoy - fecha_vencimiento)` en días.

### 7.5 Manejo de errores por fila
Cada error se registra en `carga_errores` con:
- fila Excel,
- campo,
- motivo.

## 8) Estrategia de inserción y reversión
### 8.1 Carga (snapshot por carga)
- Se crea registro en `cargas_cartera`.
- Cada fila válida se escribe en:
  - `documentos_snapshot` (histórico completo por carga),
  - `documentos` (estado vigente del documento, update por clave).

### 8.2 Reversión (admin)
- Solo se permite revertir la **última** carga procesada.
- Para cada documento de la carga:
  - si existe snapshot previo, restaura versión anterior en `documentos`;
  - si no existe snapshot previo, elimina documento del estado vigente.
- Estado de carga cambia a `revertida`.

## 9) Auditoría mínima
- Origen de documento: `documentos.id_carga_origen`.
- Log de cambios manuales/administrativos:
  - tabla, registro, campo, valor anterior, valor nuevo, usuario y fecha.

## 10) Exportaciones
- Formato MVP: CSV UTF-8 con BOM.
- Exportación habilitada por rol (`admin`, `analista`).
