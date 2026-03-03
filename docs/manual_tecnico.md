# Manual técnico (MVP Fase 1)

## Requisitos
- PHP 8.x con PDO MySQL.
- MySQL 8.x o MariaDB.
- Opcional: Composer + `phpoffice/phpspreadsheet` para importar XLSX.

## Instalación
1. Crear base de datos ejecutando `sql/schema.sql`.
2. Configurar variables de entorno:
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
3. Levantar servidor web apuntando a `public/`.
   - Ejemplo local: `php -S 0.0.0.0:8000 -t public`.
4. Ingresar a `/login.php`.

## Usuario inicial
- Email: `admin@mcm.local`
- Password inicial (hash en SQL): `Admin123*`

## Importador Excel/CSV
- Soporta XLSX si existe PhpSpreadsheet.
- Sin PhpSpreadsheet: fallback a CSV (separador coma).
- Estructura exacta esperada (en orden):
  `nit,nombre_cliente,tipo_documento,numero_documento,fecha_emision,fecha_vencimiento,valor_original,saldo_actual,dias_mora,periodo,canal,regional,asesor_comercial,ejecutivo_cartera,uen,marca`
- Clave única documento: `nit + tipo_documento + numero_documento`.
- Días de mora: usa valor archivo; si vacío, calcula en base a fecha vencimiento.
- Valida: columnas, campos críticos, duplicados en archivo, hash de archivo repetido.

## Seguridad
- Password hash con bcrypt.
- Roles: admin / analista / visualizador.
- Middleware de autenticación y autorización por rol.
