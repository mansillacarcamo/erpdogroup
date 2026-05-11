# Despliegue a Benzahosting · Sistema DOGroup

Esta guía cubre la subida del sistema completo al hosting de producción.

---

## 0) Preparación local (ya hecho)

- [x] Backups pre-deploy en `backups/` y `gastos_diarios/backups/`
- [x] WAL checkpoint aplicado a ambos `.db`
- [x] FK consistency verificada (0 huérfanos)
- [x] `dev_login.php`, `_commitmsg.txt`, `_generate_icons.php` eliminados
- [x] Migración 021 (pago_cuenta) movida a `migrations/` y registrada
- [x] ZIP de deploy generado: `deploy_dogroup_YYYYMMDD_HHMMSS.zip`

---

## 1) Subir el ZIP a cPanel

1. Ingresa a tu panel de Benzahosting (cPanel)
2. Abre **Administrador de Archivos** (`File Manager`)
3. Navega a `public_html/`
4. **Sube** el archivo `deploy_dogroup_YYYYMMDD_HHMMSS.zip`
5. Una vez subido, click derecho → **Extract** (extraer en `public_html/`)
6. Si quieres todo bajo `public_html/dogroup/`, crea esa carpeta primero, métete y extrae ahí.

---

## 2) Verificar versión de PHP

En cPanel → **Seleccionar versión de PHP** (Select PHP Version):

- [x] Versión: **PHP 8.3** o superior (igual que local)
- Extensiones requeridas (marcar):
  - [x] `pdo_sqlite`
  - [x] `sqlite3`
  - [x] `mbstring`
  - [x] `json`
  - [x] `zip`
  - [x] `fileinfo`
  - [x] `gd` o `imagick` (procesado de imágenes de perfil)
  - [x] `openssl`

Si alguna falta, márcala y guarda. Recarga la app.

---

## 3) Permisos de carpetas

Por SSH o desde el File Manager (Permissions):

```bash
# Desde la raíz del proyecto en el server
chmod 755 .
chmod 666 ocdogroup.db
chmod 666 gastos_diarios/controlgastos.db
chmod -R 755 uploads/
chmod -R 755 gastos_diarios/uploads/
chmod -R 755 backups/
chmod -R 755 gastos_diarios/backups/
```

Las carpetas `uploads/` y `backups/` ya tienen su `.htaccess` que bloquea acceso web directo.

---

## 4) Programar el cron de backup

cPanel → **Trabajos cron** (Cron Jobs).

Agregar dos líneas (una por DB):

```
30 23 * * *  /usr/local/bin/php /home/TU_USUARIO/public_html/tools/backup_db.php >> /home/TU_USUARIO/public_html/backups/_cron.log 2>&1
35 23 * * *  /usr/local/bin/php /home/TU_USUARIO/public_html/gastos_diarios/tools/backup_db.php >> /home/TU_USUARIO/public_html/gastos_diarios/backups/_cron.log 2>&1
```

⚠️ Ajusta `TU_USUARIO` a tu usuario real de cPanel.

⚠️ Si `/usr/local/bin/php` no funciona, prueba con:

```
/opt/cpanel/ea-php83/root/usr/bin/php
```

Verifica al día siguiente que el log creció (señal de que el cron corrió).

---

## 5) Verificar SSL/HTTPS

cPanel → **SSL/TLS Status**:

- [x] Tu dominio tiene certificado activo (Let's Encrypt o equivalente)
- [x] Forzar HTTPS desde **Domains → Force HTTPS Redirect** (ON)

---

## 6) Verificar funcionamiento

Abre en el navegador:

1. **Login principal**: `https://gestion.dogroup.cl/login.php`
   - Ingresa con un usuario admin existente
   - Verifica que cargan los módulos

2. **Gastos diarios**: `https://gestion.dogroup.cl/gastos_diarios/login.php`
   - Ingresa con un usuario tipo "usuario"
   - Verifica que aparece el saludo de bienvenida
   - Revisa que cargan las categorías

3. **Healthcheck remoto** (opcional): podrías ejecutar `php tools/db_healthcheck.php` por SSH si lo tienes habilitado en Benza.

---

## 7) Lista de archivos sensibles a revisar

Estos archivos requieren login admin pero su URL es predecible. Decide si quieres mantenerlos o renombrarlos:

| Archivo | Función | Recomendación |
|---|---|---|
| `debug.php` | Panel de debug del sistema | Mantener (requiere admin) |
| `diagnostico.php` | Estado del sistema | Mantener (requiere admin) |
| `fix_perms.php` | Reparación de permisos | Mantener pero úsalo solo si hay problemas |
| `backup.php` | Panel de backup manual | Mantener |
| `migrate.php` | Aplicar migraciones | Mantener (debería ejecutarse 1 vez tras el deploy) |

---

## 8) Aplicar migraciones (1 vez tras el deploy)

Después de subir, abre una vez:

```
https://gestion.dogroup.cl/migrate.php
```

Verás un listado de migraciones pendientes. Aplícalas todas. La 021 (pago_cuenta) probablemente aparezca si subes el `.db` desde otro entorno.

⚠️ Si subiste el `.db` con la migración 021 ya registrada (lo hiciste local), no aparecerá pendiente. Eso es correcto.

---

## 9) Estado de los datos

El sistema viene con datos **mínimos** preservados del entorno local:

| Tabla | Filas | Comentario |
|---|---|---|
| `clientes` | 9 | Preservados explícitamente |
| `usuarios` | 8 | Necesarios para login |
| `migrations_log` | 21 | Histórico de migraciones |
| Resto | 0 | Sistema limpio para usar de cero |

En `gastos_diarios/controlgastos.db`:

| Tabla | Filas |
|---|---|
| `usuarios` | 3 |
| `categorias_gasto` | 7 |
| Resto | 0 |

---

## 10) Post-deploy

Una vez todo funcionando:

- [ ] **Cambiar contraseñas**: ingresa como admin y cambia las contraseñas de los usuarios (las locales pueden ser inseguras).
- [ ] **Probar el flujo completo**: crea una OC de prueba → aprobación → cotización → estado de pago → ticket de despacho → combustible → gastos. Confirma que todo el flujo funciona.
- [ ] **Verificar emails**: si el sistema envía notificaciones por email, configura SMTP en cPanel.
- [ ] **Service Worker / PWA**: en gastos_diarios el SW se registra automáticamente. Verifica en Chrome DevTools → Application → Service Workers.
- [ ] **Documentar URLs**: anota las URLs principales para tu equipo.

---

## 11) Si al entrar a la URL principal te abre Gastos Diarios

Este problema lo causan archivos viejos que quedaron en el server o un Service Worker cacheado en el navegador. Solución:

### A) En el servidor (cPanel)

1. Antes de extraer el ZIP nuevo, **respalda y elimina** los archivos viejos del root:
   ```
   Archivos a eliminar antes de extraer (si existen):
   - public_html/index.php   (si pesa más de 1KB es la versión vieja del form de OC)
   - public_html/index.html  (si existe y NO es el del ZIP nuevo)
   - public_html/.htaccess   (lo reemplazará el del ZIP)
   - public_html/sw.js       (lo reemplazará el del ZIP, versión v4)
   ```
   ⚠️ **NO borres**: `ocdogroup.db`, las carpetas `uploads/`, `gastos_diarios/`, `tools/`.

2. Extrae el ZIP **encima de la carpeta**, eligiendo "**Replace all existing files**" (Reemplazar todos).

3. Verifica que el `index.php` final pesa **solo unos cientos de bytes** (es el redirect simple), no 23KB.

### B) En el navegador del usuario

Si después del paso A el usuario sigue viendo Gastos Diarios al entrar:

**Chrome/Edge desktop**:
1. F12 → pestaña **Application**
2. Lateral izquierdo: **Service Workers** → click "**Unregister**" en cada SW listado
3. **Storage** → click "**Clear site data**"
4. Cerrar pestaña, abrir de nuevo

**Mobile**:
1. Si tiene la **PWA instalada** (icono en pantalla de inicio): es esperado que abra Gastos Diarios. La PWA va directo. **Para volver al sistema principal, abre la URL en el navegador**, no desde el ícono.
2. Si no tiene PWA instalada: borra caché del sitio en Configuración → Privacidad → Borrar datos.

### C) El index.html nuevo que incluí

El ZIP incluye un `index.html` defensivo que se ejecuta **antes** de `index.php` y limpia automáticamente caches obsoletas del navegador. Este archivo:

- Limpia caches viejas del root (no toca las de gastos_diarios)
- Redirige a `index.php` que decide si vas al login o al dashboard
- Headers `no-cache` para que NUNCA se cachee

Si el usuario abre la URL principal recién subido el deploy, en la **primera visita** verá brevemente "Redirigiendo..." y luego el sistema correcto.

---

## 12) Si algo sale mal

- **DB corrupto** → Restaurar desde `backups/ocdogroup_pre_deploy_*.db` (subido en el ZIP).
- **500 Internal Server Error** → Revisa `error_log` en cPanel. Suele ser permisos de `.db` (chmod 666).
- **Migración fallida** → Abre `migrate.php?debug=1` para ver el detalle. Aplica manualmente si es necesario.
- **Foto de perfil no carga** → Verifica que `uploads/perfiles/` tenga 755.
- **Service Worker cacheado raro** → DevTools → Application → Service Workers → Unregister. Recargar.

---

## Referencia rápida

- Documento de auto-memoria sobre Benza: ver memorias del proyecto
- WAL ya activo en ambos DBs
- Backup local antes de cualquier cambio destructivo: ya hecho

¡Buena suerte con el deploy! 🚀
