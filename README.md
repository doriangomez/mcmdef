# MCM - Sistema de Gestión de Cartera y Recaudos (MVP Fase 1)

Aplicativo MVP en **PHP + MySQL** para gestión de cartera y recaudos.

Incluye:
- Login y control de acceso por roles (admin, analista, visualizador).
- Carga de cartera desde archivo SAP (CSV / XLSX con SimpleXLSX embebido).
- Consulta de cartera con filtros combinables, ordenamiento y exportación.
- Bitácora de gestiones y compromisos (sin borrado, con anulación).
- Auditoría operativa y trazabilidad de carga origen por documento.
- Reportes operativos exportables.

## Stack
- Backend: PHP 8.x.
- Base de datos: MySQL 8.x / MariaDB.
- Frontend: HTML + CSS + JS básico.
- Importación Excel:
  - Excel: SimpleXLSX embebido en `app/libraries/SimpleXLSX.php` (sin Composer).
  - Alternativa implementada: CSV sin dependencias externas.
- Exportación: CSV.

## Estructura principal
- `public/`: rutas web.
  - `login.php`, `logout.php`, `index.php`.
  - `/cargas`, `/cartera`, `/gestion`, `/reportes`, `/admin`.
- `app/`: configuración, middlewares, servicios y vistas.
- `sql/schema.sql`: DDL + datos iniciales.
- `docs/`: manual técnico y manual de usuario.

## Rutas clave
- `/login.php`
- `/index.php`
- `/cargas/nueva.php`
- `/cargas/historial.php`
- `/cargas/detalle.php?id=...`
- `/cartera/lista.php`
- `/cartera/cliente.php?id_cliente=...`
- `/cartera/documento.php?id_documento=...`
- `/gestion/nueva.php`
- `/gestion/lista.php`
- `/reportes/index.php`
- `/admin/usuarios.php`
- `/admin/auditoria.php`

## Instalación rápida
1. Crear BD ejecutando `sql/schema.sql`.
2. Configurar variables de entorno:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
3. Configurar servidor web apuntando a `public/`.
4. Verificar que exista `app/libraries/SimpleXLSX.php` en el despliegue para soportar XLSX sin dependencias externas.
5. Verificar permisos de logs: carpeta `logs/` y archivo `logs/php-errors.log` con permisos de escritura del usuario de PHP/Apache.
6. Ingresar por `/login.php`.

Usuario inicial:
- Email: `admin@mcm.local`
- Password objetivo: `Admin123*`


## Logging de PHP en servidor
- El bootstrap de conexión (`app/config/db.php`) crea `logs/php-errors.log` automáticamente.
- Se configura:
  - `display_errors = 0`
  - `log_errors = 1`
  - `error_reporting(E_ALL)`
  - `error_log = /logs/php-errors.log` dentro del proyecto.

## Carga de cartera (XLSX/XLS/CSV)
- Extensiones permitidas: `.xlsx`, `.xls`, `.csv`.
- La carga XLSX se procesa con `SimpleXLSX` embebido, sin Composer ni dependencias externas.
- La validación recorre el archivo completo y acumula errores por fila/campo/valor/descripción antes de insertar.
- Si hay un solo error, no se inserta ningún dato.
- Se habilita descarga de reporte de errores en CSV desde la misma pantalla de carga.
