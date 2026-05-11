# Campo SALIDA en Tickets de Despacho — DOGroup

Agrega el campo **SALIDA** (origen del carguío) a los tickets de despacho.
La salida se selecciona desde la tabla `obras`, igual que la obra de destino.

## Archivos del paquete

### Migración (NUEVA)
- `migrations/014_ticket_salida.php` — agrega `salida_id` y `salida_nombre` a `tickets_despacho` + índice

### Formularios y captura (MODIFICADOS)
- `tickets_despacho.php` — listado principal: nuevo selector **Salida (origen)** antes de **Obra (destino)** + INSERT actualizado + columna en tabla
- `despacho_conductor_portal.php` — portal del conductor (móvil): selector Salida en formulario crear y editar + INSERT/UPDATE + precarga JS

### Vistas detalle (MODIFICADO)
- `tickets_despacho_ver.php` — vista impresa y pantalla del ticket: muestra Salida cuando existe

### Reportes y exportaciones (MODIFICADOS)
- `reporte_despacho.php` — pantalla, versión imprimible, **Excel adjunto al email** (14 columnas) y tabla del email HTML
- `tickets_despacho_reporte_diario.php` — historial diario con columna Salida
- `tickets_despacho_cierre.php` — CSV de cierre, tabla de pantalla y tabla del email
- `despacho_externo_portal.php` — Excel de Carchek (revisión) + vista detalle del ticket en validación
- `despacho_mis_reportes.php` — reporte personal del conductor con columna Salida

## Cómo instalarlo

### Paso 1 — Copiar archivos
Copia los archivos del ZIP a `C:\laragon\www\dogroup\` respetando carpetas:
- raíz: todos los `.php`
- `migrations/014_ticket_salida.php`

### Paso 2 — Aplicar migración
Solo abre cualquier página del sistema. El runner aplica la migración automáticamente.

Verifica con:
```sql
SELECT * FROM migrations_log WHERE archivo = '014_ticket_salida.php';
```
Debe aparecer con estado `ok`.

### Paso 3 — Probar
1. Abre **Tickets de Despacho → Nuevo ticket**
2. Verás dos selectores: **Salida (origen)** y **Obra (destino)**, ambos con la lista de obras
3. Crea un ticket de prueba
4. Revisa que aparezca en:
   - El listado principal (columna Salida)
   - La vista del ticket
   - El reporte diario
   - El Excel exportado por email
   - El historial diario
   - El portal de Carchek

## Notas

### Compatibilidad con tickets antiguos
Los tickets creados antes de esta migración tendrán `salida_id` y `salida_nombre` en NULL/vacío. En todas las vistas se muestran como **"—"** sin romper nada.

### Tabla `obras`
El selector de Salida usa la misma tabla `obras` que el selector de Obra (destino). Esto asume que tanto el origen como el destino son obras del catálogo. Si se agregan nuevas obras al catálogo, aparecerán automáticamente en ambos selectores.

### Lo que NO cambió
- La tabla `carchek_log` no recibe `salida_nombre` (es solo para auditoría histórica de Carchek). Si quieres registrarlo también ahí, sería una migración adicional (`015_carchek_log_salida.php`).
- Los conductores con `obra_id` asignada en su perfil siguen heredando esa obra como destino por defecto (no afecta a la Salida).

### Estructura final de la tabla
```
tickets_despacho:
  ...
  obra_id        INTEGER  -- destino (ya existía)
  obra_nombre    TEXT     -- destino denormalizado
  salida_id      INTEGER  -- NUEVO: origen del carguío
  salida_nombre  TEXT     -- NUEVO: origen denormalizado
  destino        TEXT     -- destino libre (ya existía, se mantiene)
  destino_id     INTEGER  -- destino_externo (ya existía)
  ...
```

---
© 2024 Bynari SpA · DOGroup
