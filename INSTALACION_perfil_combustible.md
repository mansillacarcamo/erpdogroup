# Perfil de acceso independiente para Responsables de Combustible

Crea un perfil de login propio para los responsables del módulo de combustible,
igual que ya existen para conductores y Carchek.

## Qué hace

- Los responsables tienen su **propio usuario y contraseña**, independientes del sistema interno.
- Acceden por una pantalla de login dedicada → `combustible_responsable_login.php`.
- Una vez logueados, entran a un **portal móvil-first** para registrar hojas y vales sin cargar todo el sistema.
- El **admin del módulo** crea las credenciales desde `admin_combustible.php`.
- Aparece como tercer botón en el login principal: **Conductores · Carchek · Combustible**.

## Archivos del paquete

### NUEVOS
- `migrations/015_combustible_resp_login.php` — agrega `usuario`, `password_hash`, `email`, `fono` a `combustible_responsables` + índice único en usuario
- `combustible_responsable_login.php` — pantalla de login con tema dorado/oscuro DOGroup
- `combustible_responsable_portal.php` — portal móvil del responsable (KPIs, listado de hojas, formulario de nueva hoja con 15 vales)

### MODIFICADOS
- `config.php` — helpers `getCombResponsableSession()`, `isCombResponsableLoggedIn()`, `requireCombResponsable()`
- `login.php` — nuevo botón "Combustible" junto a Conductores y Carchek
- `admin_combustible.php` — bloque "Credenciales del Portal Combustible" en el formulario de responsable + columna "Portal Combustible" en el listado

## Instalación

### Paso 1 — Copiar archivos
Descomprime el ZIP en `C:\laragon\www\dogroup\` respetando carpetas.

### Paso 2 — La migración se aplica sola
Al abrir cualquier página, el runner detecta `015_combustible_resp_login.php` y la ejecuta.

Verifica con:
```sql
SELECT * FROM migrations_log WHERE archivo = '015_combustible_resp_login.php';
```

### Paso 3 — Crear un responsable con acceso
1. Ingresa al sistema como admin
2. Ve a **Distribución de Combustible → Admin** (o `admin_combustible.php`)
3. En la pestaña **Responsables**:
   - Llena nombre, RUT, obra
   - En la sección **🔑 Credenciales del Portal Combustible**:
     - **Usuario login**: nombre corto, ej `jperez`
     - **Contraseña**: mínimo 4 caracteres
     - Email y fono son opcionales
   - Guardar

### Paso 4 — Probar el portal
1. Ve a `http://tu-servidor/dogroup/login.php`
2. Haz clic en el botón **Combustible** (el tercero, dorado)
3. Ingresa con el usuario y clave que asignaste
4. Ya puedes registrar hojas desde el celular

## Cómo funciona en el portal

Cuando el responsable entra ve:
- **Top bar oscura** con su nombre y obra asignada
- **4 KPIs del mes**: total litros, hojas, diésel, gasolina
- **Selector de mes** para navegar el historial
- **Lista de sus hojas** del mes con tags (combustible/fuente/estado)
- **Botón flotante (+)** abajo a la derecha para crear nueva hoja
- **Sheet móvil** que sube desde abajo con el formulario de hoja + 3 vales pre-cargados (puede agregar hasta 15)
- **Total de litros** se calcula en vivo mientras escribe

Las hojas se crean con `responsable_id` apuntando al responsable logueado y `creado_por = NULL`
(porque no es un usuario interno del sistema).

El responsable puede **cerrar una hoja** desde el listado para que ya no se edite.

## Permisos y seguridad

- **Sesión separada**: usa `$_SESSION['comb_responsable']`, no toca `$_SESSION['usuario']`
- **Validación de usuario único**: índice único parcial en `usuario` (excluyendo vacíos)
- **Hash bcrypt**: contraseñas con `password_hash(... PASSWORD_DEFAULT)` igual que el resto del sistema
- **Edición sin perder clave**: dejar el campo "Contraseña" vacío al editar mantiene el hash anterior. Para cambiar, escribir nueva contraseña.
- **Solo ve lo suyo**: el portal filtra `WHERE responsable_id = mi_id` en todos los queries
- **Solo edita hojas abiertas**: los métodos POST validan `estado='abierta'` y `responsable_id = mi_id`

## Estructura final de la tabla

```
combustible_responsables:
  id, usuario_id, nombre, rut, telefono,
  obra_id, obra_nombre, activo, creado_por, creado_en,
  usuario        TEXT  -- NUEVO: login independiente
  password_hash  TEXT  -- NUEVO: bcrypt del password
  email          TEXT  -- NUEVO
  fono           TEXT  -- NUEVO
```

## Notas

### Convivencia con `usuario_id` (sistema interno)
- El campo `usuario_id` (vincular a un usuario interno) **sigue funcionando**: los usuarios internos pueden seguir registrando hojas desde el sistema completo.
- El nuevo campo `usuario` (login del portal) es **independiente** y permite acceso al portal móvil.
- Un responsable puede tener uno, otro, ambos, o ninguno.

### Diseño visual
- El portal usa colores DOGroup: oscuro `#1a1a2e` + dorado `#d97706`
- 100% mobile-first con sheet sliding bottom (no requiere scroll horizontal)
- PWA-ready: el portal incluye `<link rel="manifest">` y registra el service worker

---
© 2024 Bynari SpA · DOGroup
