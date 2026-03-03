# MCM - Sistema de Gestión de Cartera y Recaudos (MVP Fase 1)

Aplicativo MVP en **PHP + MySQL** para gestión de cartera y recaudos.

Incluye:
- Login y control de acceso por roles (admin, analista, visualizador).
- Carga de cartera desde archivo SAP (CSV / XLSX con PhpSpreadsheet).
- Consulta de cartera con filtros combinables, ordenamiento y exportación.
- Bitácora de gestiones y compromisos (sin borrado, con anulación).
- Auditoría operativa y trazabilidad de carga origen por documento.
- Reportes operativos exportables.

## Stack
- Backend: PHP 8.x.
- Base de datos: MySQL 8.x / MariaDB.
- Frontend: HTML + CSS + JS básico.
- Importación Excel:
  - Preferente: PhpSpreadsheet (Composer, si disponible).
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
4. Ingresar por `/login.php`.

Usuario inicial:
- Email: `admin@mcm.local`
- Password objetivo: `Admin123*`
