# Manual de usuario (MVP Fase 1)

## 1) Ingreso al sistema
1. Abra `/login.php`.
2. Ingrese correo o usuario y contraseña.
3. Clic en **Ingresar**.
4. Si el usuario está inactivo o las credenciales son inválidas, verá mensaje de error.

## 2) Dashboard
Ruta: `/index.php`

Visualiza:
- total cartera vigente,
- total cartera vencida,
- total saldo,
- documentos vencidos,
- compromisos pendientes,
- últimas cargas registradas.

Accesos rápidos:
- Cargar cartera,
- Historial de cargas,
- Consulta de cartera,
- Gestión,
- Reportes.

## 3) Carga de cartera (SAP)
### 3.1 Nueva carga
Ruta: `/cargas/nueva.php` (admin/analista)

1. Cargue archivo CSV o XLSX/XLS.
2. Respete la plantilla exacta y orden de columnas.
3. Pulse **Validar y procesar**.

Resultados posibles:
- **Carga procesada**: indica cantidad de nuevos y actualizados.
- **Carga con errores**: muestra errores por fila/campo/motivo.

### 3.2 Historial y detalle
- `/cargas/historial.php`
- `/cargas/detalle.php?id=...`

Desde detalle puede ver:
- métricas de carga,
- errores persistidos,
- snapshot de registros importados.

Si es administrador, puede **revertir** la última carga procesada.

## 4) Consulta de cartera
Ruta: `/cartera/lista.php`

Filtros combinables:
- cliente / NIT,
- tipo y número de documento,
- canal, regional,
- asesor comercial, ejecutivo de cartera,
- UEN, marca,
- periodo,
- rango de días de mora.

Además:
- ordenamiento por columna,
- paginación,
- exportación CSV (admin/analista).

Detalle:
- cliente: `/cartera/cliente.php?id_cliente=...`
- documento: `/cartera/documento.php?id_documento=...`

## 5) Bitácora de gestión y compromisos
Rutas:
- `/gestion/nueva.php`
- `/gestion/lista.php`

Flujo:
1. Registre gestión (novedad, compromiso, seguimiento, etc.).
2. Relacione cliente y/o documento.
3. Defina compromiso opcional (fecha, valor, estado).
4. Consulte historial con filtros.
5. Si aplica, anule gestión con motivo (sin borrado físico).

Estados de compromiso:
- pendiente
- cumplido
- incumplido

## 6) Reportes
Ruta: `/reportes/index.php`

Reportes incluidos:
- cartera vigente y vencida,
- mora por rangos,
- cartera por canal,
- cartera por UEN,
- cartera por regional,
- cartera por asesor,
- compromisos y estado,
- comparativo por periodo.

Cada reporte tiene:
- filtros,
- vista previa tabular,
- exportación CSV (según rol).

## 7) Administración y auditoría (admin)
- Usuarios: `/admin/usuarios.php`
  - crear usuario,
  - activar/inactivar,
  - reset de contraseña temporal.
- Auditoría: `/admin/auditoria.php`
  - trazabilidad por tabla/campo/usuario/fecha.
