# Control de Gastos

Sistema web (mobile-first + PWA) para controlar gastos mensuales por usuario, con flujo:
**Usuario → Jefe Zonal → Validador**.

## Características

-   Perfiles con monto mensual asignado y arrastre de saldo.
-   Registro de gastos con foto desde la cámara del móvil.
-   Pop-up diario de bienvenida con saldo restante.
-   Cierre mensual enviado al **Jefe Zonal** (aprueba/rechaza) y luego al **Validador**.
-   Exportación a **PDF** lista para imprimir / guardar.
-   Envío por **correo** (función `mail()` del servidor).
-   100% responsive (Bootstrap 5) e instalable como PWA.

## Stack

-   PHP 8 + SQLite (PDO) — sin dependencias externas.
-   Bootstrap 5 + Bootstrap Icons (CDN).
-   Service Worker + manifest para PWA.

## Estructura

```
controlgastos/
├── config.php                  # DB + helpers + esquema
├── index.php                   # Redirige según rol
├── login.php / registro.php / recuperar.php / logout.php
├── dashboard.php               # Inicio del usuario (popup diario)
├── gasto_nuevo.php             # Registrar gasto + foto
├── mis_gastos.php              # Lista mensual del usuario
├── gasto_ver.php / gasto_eliminar.php
├── cierre_mes.php              # Cierre mensual del usuario
├── jefe_dashboard.php          # Equipo del jefe zonal
├── jefe_cierres.php / jefe_revisar_cierre.php / jefe_ver_usuario.php
├── validador_dashboard.php     # Cierres por validar
├── exportar_pdf.php            # Vista imprimible (PDF)
├── enviar_correo.php
├── perfil.php
├── admin_usuarios.php / admin_asignaciones.php / admin_gastos.php / admin_categorias.php
├── manifest.json + sw.js + img/icon-*.png    # PWA
├── includes/  (head.php, nav.php, foot.php)
├── css/app.css
├── js/app.js
└── uploads/                    # Boletas
```

## Roles

| Rol         | Puede                                                                    |
| ----------- | ------------------------------------------------------------------------ |
| `usuario`   | Registrar gastos, ver saldo, enviar cierre mensual                       |
| `jefe`      | Ver gastos de su equipo, aprobar/rechazar cierres → Validador            |
| `validador` | Validar cierres, exportar PDF, enviar correo                             |
| `admin`     | Gestionar usuarios, asignar montos, definir jerarquía, ver todo, categorías |

## Instalación local

```bash
cd controlgastos
php -S localhost:8000
```

Abrir: <http://localhost:8000>

**Credenciales por defecto del admin:**
-   Usuario: `admin@controlgastos.local`
-   Clave:   `admin123`

## Despliegue (Benza Hosting / cPanel)

1.  Sube por FTP toda la carpeta `controlgastos/` a `public_html/controlgastos/`.
2.  Asegúrate que la carpeta `uploads/` tenga permisos `0775` (escritura).
3.  El archivo SQLite `controlgastos.db` se crea automáticamente al primer acceso.
4.  Verifica que PHP 8+ esté activo y la extensión **PDO_SQLITE** habilitada.

## Flujo del mes

1.  **Admin** asigna monto del mes a cada usuario (`admin_asignaciones.php`).
2.  **Usuario** ingresa cada gasto con foto.
3.  Cada día el usuario ve un **popup** con su saldo restante.
4.  Al finalizar el mes (o al consumir el monto) → **Enviar cierre** al Jefe.
5.  **Jefe Zonal** revisa y aprueba → pasa al **Validador**.
6.  **Validador** valida y puede exportar PDF / enviar correo.
