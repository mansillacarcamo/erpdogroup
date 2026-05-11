# Módulo Distribución de Combustible — DOGroup

## Qué incluye este paquete

### Archivos NUEVOS del módulo
- `combustible.php` — Dashboard con KPIs, **gráficos** y listado de hojas
- `combustible_hoja_nueva.php` — Crear hoja diaria con sus 15 vales
- `combustible_hoja_ver.php` — Ver/editar/cerrar una hoja
- `combustible_export_excel.php` — Exportar Excel (1 hoja o todas las del mes)
- `admin_combustible.php` — Panel admin: responsables y admins del módulo
- `migrations/013_combustible.php` — Migración que crea las 4 tablas

### Archivos MODIFICADOS del sistema
- `config.php` — Registra el módulo `combustible` y agrega los helpers (`isCombustibleAdmin`, `requireCombustibleAcceso`, etc.). **Nota**: ya tenía la mayoría del código preparado.
- `inicio.php` — Tarjeta del módulo en el panel principal + banner "Instalar app DOGroup"
- `includes/header.php` — Manifest, theme-color y meta tags PWA en todas las páginas
- `manifest.json` — Renombrado a "DOGroup" con shortcuts a Combustible
- `sw.js` — Service Worker mejorado (network-first para PHP, cache-first para assets)

## Cómo instalarlo

### Paso 1 — Copiar archivos
Copia el contenido del ZIP a `C:\laragon\www\dogroup\` (o tu carpeta del proyecto), respetando las subcarpetas:
- raíz del proyecto: todos los `.php`, `manifest.json`, `sw.js`
- `includes/header.php`
- `migrations/013_combustible.php`

### Paso 2 — Aplicar la migración
Solo abre cualquier página del sistema (ej. `inicio.php`). El runner de migraciones detecta la `013` nueva y la aplica automáticamente. Lo puedes verificar en la BD:

```sql
SELECT * FROM migrations_log ORDER BY id DESC LIMIT 3;
```

Debe aparecer `013_combustible.php` con estado `ok`.

### Paso 3 — Asignar permisos (opcional)
Por defecto **todos los usuarios admin globales** pueden usar el módulo. Si quieres designar admins específicos del módulo (sin que sean admin global del sistema):

1. Entra como admin
2. Ve a **Distribución de Combustible → Admin**
3. En la pestaña **Admins** agrega los usuarios que pueden gestionar
4. En la pestaña **Responsables** crea los responsables (uno por obra) que registrarán los vales

## Funcionalidades clave

### En escritorio
- KPIs del mes: hojas, total de litros, diésel, gasolina
- **4 gráficos**: evolución diaria, diésel vs gasolina, top 5 obras, top 5 patentes
- Tabla con filtros (mes, obra, responsable, tipo, estado)
- Acciones por fila: ver/editar, exportar Excel individual
- Botón "Excel del mes" para todas las hojas filtradas

### En móvil (PWA)
- Vista responsive optimizada
- Banner **"Instalar DOGroup"** que aparece en Chrome/Edge móvil
- Al instalar: ícono en la pantalla de inicio con logo DOGroup
- Atajos directos al instalar: Combustible, Nueva hoja, Despacho
- Service Worker para uso offline parcial (assets cacheados)

### Permisos
- **Admin global** (`rol='admin'`): acceso total
- **Admin del módulo** (`combustible_admins`): igual que admin global pero solo en este módulo
- **Responsable** (`combustible_responsables`): solo registra/ve sus propias hojas

### Exportación Excel
- **Una hoja**: `?hoja_id=N`
- **Todas las del mes filtradas**: `?multi=1&mes=2026-04` (más filtros opcionales)
- El formato replica el papel "Distribución de Combustible" con cabecera, los 15 vales y el total.

## Estructura de la BD (4 tablas nuevas)

| Tabla | Para qué |
|---|---|
| `combustible_responsables` | Responsables de obra que operan camión/storage |
| `combustible_admins` | Usuarios con permiso de administración del módulo |
| `combustible_hojas` | Encabezado de cada hoja diaria (fecha, obra, miter, saldo, total) |
| `combustible_vales` | Las 15 líneas de cada hoja (código, vale, patente, litros, etc.) |

## Notas técnicas

- **Chart.js 4.4.1** desde CDN — sin build tools
- Respeta la **regla de migraciones del proyecto**: ningún `CREATE TABLE` en `config.php`
- Compatible con SQLite WAL ya activo
- El sidebar ya tenía las entradas del módulo preparadas

---
© 2024 Bynari SpA · DOGroup
