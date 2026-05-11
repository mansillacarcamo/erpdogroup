# Chequeo completo del proyecto DOGroup — Reporte y correcciones

## Lo que se revisó (13 chequeos)

1. ✅ Balance sintáctico de los 105 archivos PHP
2. ✅ Patrones peligrosos (SQL injection, headers sin exit)
3. ✅ Referencias a archivos PHP que no existen
4. ✅ Tablas referenciadas en SQL que no existen
5. ✅ Funciones llamadas que no están definidas
6. ✅ Enlaces del sidebar y pantallas principales
7. ✅ Enlaces rotos en archivos vivos del sistema
8. ✅ Flujo completo de Órdenes de Compra
9. ✅ Protección de páginas críticas (`requireAuth`)
10. ✅ Patrones peligrosos avanzados
11. ✅ Consistencia de claves de array entre query y uso
12. ✅ Formularios POST con sus handlers
13. ✅ INSERTs con columnas que existen en la BD

## Errores encontrados y corregidos

### 🔴 Crítico 1 — Botones de Órdenes de Compra rotos
**Síntoma**: Hacer clic en "Nueva OC", "Historial" o "Aprobaciones" desde el panel principal no hacía nada (404).

**Causa**: En `inicio.php` los botones apuntaban a archivos inexistentes:
| Antes (roto) | Ahora (correcto) |
|---|---|
| `oc_nueva.php` | `index.php` |
| `oc_lista.php` | `historial.php` |
| `oc_lista.php?estado=pendiente` | `mis_aprobaciones.php` |

**Archivo modificado**: `inicio.php`

---

### 🔴 Crítico 2 — Botón "Administrar Faena" roto
**Síntoma**: Botón aparecía pero al hacer clic lleva a 404.

**Causa**: Apuntaba a `faena_admin.php` que no existe.

**Solución**: Botón eliminado del panel y del sidebar (la administración real de faena se hace vía `faena_supervisor.php`).

**Archivos modificados**: `inicio.php`, `includes/header.php`

---

### 🔴 Crítico 3 — Cotizaciones: error al guardar
**Síntoma**: Al crear una cotización nueva, el sistema fallaba con error de columna inexistente.

**Causa**: `cotizacion.php` insertaba la columna `creada_por_cargo` que nunca se había agregado a la tabla `cotizaciones` (en cambio, `ordenes_compra` ya tenía `preparada_por_cargo`).

**Solución**: Migración `016_fix_columnas_faltantes.php` que:
- Agrega la columna `creada_por_cargo` a `cotizaciones`
- Rellena el cargo de cotizaciones existentes consultando la tabla `usuarios`

---

### 🔴 Crítico 4 — Carchek: error al enviar reporte
**Síntoma**: Al enviar el reporte de Carchek por email, fallaba al registrar el envío.

**Causa**: `despacho_externo_portal.php` insertaba en `despacho_reporte_carchek` las columnas `fecha`, `json_data`, `enviado_a` que no existían.

**Solución**: Misma migración `016_fix_columnas_faltantes.php` agrega las 3 columnas faltantes.

---

### 🟡 Bug menor — exportar_pdf_faena.php
**Síntoma**: Si alguien encontraba la URL directa, se rompía con error de sesión y tabla.

**Causa**: Usaba `$_SESSION['user_id']` (no existe) y consultaba `obras_faena` (tabla inexistente).

**Solución**: Reemplazado por `$usuario['id']` y `obras` (los nombres correctos del sistema actual).

**Archivo modificado**: `exportar_pdf_faena.php`

## Hallazgos informativos (no críticos)

### Archivos huérfanos del módulo viejo "Control de Gastos"
Estos NO afectan al sistema actual porque no están enlazados desde el sidebar/inicio activo:
- `admin_usuarios.php`, `registro.php`, `recuperar.php` — sistema de usuarios viejo
- `admin_categorias.php`, `admin_asignaciones.php`, `admin_gastos.php` — admin viejo
- `gasto_*.php`, `cierre_mes.php`, `dashboard.php`, `mis_gastos.php` — gastos viejos
- `jefe_*.php`, `validador_*.php` — flujo viejo de aprobaciones
- `notificaciones_api.php`, `exportar_pdf.php`, `adjunto_eliminar.php`, `perfil.php`
- `includes/nav.php`, `includes/foot.php` — sidebar/footer viejos

Tienen errores (tablas inexistentes, funciones como `requireRol` con L, `flash`, etc) pero **no se llaman desde ningún archivo activo**. El sistema actual usa `includes/header.php` y `includes/footer.php`.

**Recomendación**: Si quieres puedo eliminarlos en una próxima iteración, pero no es urgente. Prefiero dejarlos por si tienen valor de referencia.

### `tickets_despacho.php` referencia tabla `despacho_notificaciones` inexistente
Está dentro de un `try/catch` que silencia el error. No rompe nada visible al usuario, solo es código muerto. Se puede limpiar más adelante.

## Cómo instalar los fixes

### Paso 1 — Copiar archivos del ZIP
Descomprime en `C:\laragon\www\dogroup\` respetando carpetas:
- `inicio.php` (raíz)
- `includes/header.php`
- `exportar_pdf_faena.php` (raíz)
- `migrations/016_fix_columnas_faltantes.php`

### Paso 2 — La migración se aplica sola
Al abrir cualquier página, el runner detecta `016_fix_columnas_faltantes.php` y ejecuta automáticamente.

Verifica con:
```sql
SELECT * FROM migrations_log WHERE archivo = '016_fix_columnas_faltantes.php';
```

### Paso 3 — Probar los botones de OC
1. Ve a `inicio.php`
2. En la tarjeta "Órdenes de Compra" prueba:
   - **Nueva OC** → debe abrir `index.php` con el formulario
   - **Historial** → debe abrir `historial.php` con la lista
   - **Aprobaciones** (si tienes pendientes) → debe abrir `mis_aprobaciones.php`

### Paso 4 — Probar cotización nueva
1. Sidebar → Cotizaciones → Nueva Cotización
2. Llenar datos y guardar
3. Debe redirigir a `ver_cotizacion.php` sin errores

## Estado final del proyecto

```
[1] Enlaces rotos en archivos vivos:           ✓ Limpio
[2] INSERTs con columnas inexistentes:          ✓ Limpio
[3] Sintaxis PHP en 105 archivos:               ✓ Limpio
[4] Permisos requireAuth/requireModulo:         ✓ Limpio
[5] Botones del flujo de OC:                    ✓ Funcionando
```

---
© 2024 Bynari SpA · DOGroup
