# ️ Manual Maestro de Administración, Operación y Continuidad TI
## Ecosistema Digital Institucional: Servidor Físico On-Premise y Servidor Dedicado Cloud
> **Documento Oficial de Traspaso Técnico, Continuidad Operativa y Recuperación ante Desastres (Disaster Recovery / Runbooks SRE)**  
> **Destinatario:** Administrador de Sistemas TI / Ingeniero Informático a cargo.  
> **Ámbito de Aplicación:** Infraestructura de Red Escolar, Servidor On-Premise (`ccg-fisico`), Hosting Dedicado Cloud (`colegiocastelgandolfo.cl`), Bases de Datos, Correo SMTP, Crons y Automatizaciones.  
> **Fecha de Consolidación:** Octubre 2026.

---

##  Tabla de Contenidos
1. [Propósito y Ficha Técnica del Ecosistema](#1-propósito-y-ficha-técnica-del-ecosistema)
2. [Topología de Red, DHCP L2 y DNS Local](#2-topología-de-red-dhcp-l2-y-dns-local)
3. [Servidor Físico On-Premise (`ccg-fisico`)](#3-servidor-físico-on-premise-ccg-fisico)
   - 3.1. Acceso y Usuarios del Sistema
   - 3.2. Estructura de Almacenamiento en Disco
   - 3.3. Servicios Systemd y Puertos
   - 3.4. CastelBoard: Portafolio y Timbre Escolar Inteligente
   - 3.5. EduDocente Studio: Generador Curricular Asistido por IA
   - 3.6. CCG Core Admin: Panel Central de Servidor Físico
   - 3.7. Tailscale Funnel: Publicación Segura hacia Internet
4. [Servidor Dedicado Cloud / Hosting cPanel (`colegiocastelgandolfo.cl`)](#4-servidor-dedicado-cloud--hosting-cpanel)
   - 4.1. Acceso a cPanel y Servidor Web LiteSpeed
   - 4.2. Portal Administrativo `/admin/` (CastelRoomKeeper)
   - 4.3. Calendario de Computación (Salas Básica y Media)
   - 4.4. Calendario de Biblioteca (Espacio Único)
   - 4.5. Módulos de Incidencias, Usuarios y Documentos
   - 4.6. Seguridad de Sesiones, Tokens y Bitácora Inmutable
5. [Bases de Datos Institucionales (MySQL y SQLite)](#5-bases-de-datos-institucionales)
   - 5.1. MySQL Cloud (cPanel): Conexión, Esquema y Tablas
   - 5.2. Mecanismo de Fallback Resiliente a Archivos JSON
   - 5.3. SQLite WAL en Servidor Físico
   - 5.4. Procedimientos de Respaldo y Restauración
6. [Sistema de Correos Automáticos y Notificaciones SMTP](#6-sistema-de-correos-automáticos-y-notificaciones-smtp)
   - 6.1. Configuración de Envío SMTP (`mail_config.php`)
   - 6.2. Motor de Envío Asíncrono y Cola Persistente
   - 6.3. Catálogo de Notificaciones Automáticas
   - 6.4. Avisos Predictivos de Inicio de Clase (`calendar_alerts.php`)
   - 6.5. Diagnóstico y Pruebas en Vivo (`mail-test-calendar.php`)
7. [Sistemas Cron, Timers y Procesos Automáticos](#7-sistemas-cron-timers-y-procesos-automáticos)
8. [Procedimientos Operativos Estándar (Runbooks) y Solución de Incidentes](#8-procedimientos-operativos-estándar-runbooks)
   - Caso A: Corte de Energía y Reinicio del Servidor Físico
   - Caso B: Caída del Enlace de Internet en el Colegio (Modo Offline)
   - Caso C: Desbloqueo y Reseteo de Contraseña de un Docente
   - Caso D: Falla en la Entrega de Correos Institucionales
   - Caso E: Bloqueo de Horarios por Actos Cívicos o Mantenimiento
   - Caso F: Despliegue de Actualizaciones de Código a Producción
9. [Directorio Maestro de Archivos, Rutas y Puertos](#9-directorio-maestro-de-archivos-rutas-y-puertos)

---

## 1. Propósito y Ficha Técnica del Ecosistema

Este documento tiene como objetivo garantizar la **continuidad operativa ininterrumpida** de todas las plataformas tecnológicas del establecimiento en caso de relevo, ausencia o traspaso del administrador titular. Cualquier profesional de TI entrante debe poder operar, mantener, diagnosticar y restaurar el sistema siguiendo las instrucciones aquí detalladas.

### Ficha Técnica de Nodos

| Nodo | Ubicación | Sistema / Stack | Acceso y Red |
|---|---|---|---|
| **`ccg-fisico`** | Rack de Informática (Pabellón Administración / Laboratorio) | Ubuntu Server 24.04 LTS / Python 3.12 / SQLite WAL / Nginx / dnsmasq | IP Local: `192.168.0.120`<br>SSH: `ssh ccgadmin@ccg-fisico.tail0e08b5.ts.net` o `ssh ccgadmin@192.168.0.120`<br>Usuario admin: `ccgadmin` / Servicios: `admin-colegio` |
| **`CCG Cloud (cPanel)`** | Servidor Dedicado Hosting en Datacenter | LiteSpeed Web Server / PHP 8.1 / MariaDB (MySQL) / cPanel | Dominio: `colegiocastelgandolfo.cl`<br>Puerto cPanel: `2083`<br>Ruta Web: `/home/colegioc/public_html/` |
| **Red LAN Escolar** | Todo el campus (Salas, Labs, Oficinas) | 1 Router Fibra (Gateway `192.168.0.1`), 8 Switches, 6 Access Points | Subred: `192.168.0.0/24`<br>DHCP/DNS: Gestionado por `ccg-fisico` |

---

## 2. Topología de Red, DHCP L2 y DNS Local

El servidor físico `ccg-fisico` actúa como el corazón de red de la institución mediante el servicio **`dnsmasq`**.

### 2.1. Arquitectura de Red Local
```
                    [ Enlace Fibra Óptica ]
                              │
                    [ Router 192.168.0.1 ]
                              │
                    [ Switch Principal L2 ]
                              │
        ┌─────────────────────┼─────────────────────┐
        ▼                     ▼                     ▼
[ CCG Físico ]       [ Laboratorios ]      [ Oficinas / Salas ]
192.168.0.120        PCs Alumnos Media     PCs Profesores
DHCP Server L2       PCs Alumnos Básica    Impresoras Ricoh
DNS Local            Access Points         Dispositivos WiFi
```

### 2.2. Parámetros del Servidor DHCP
- **Servicio:** `dnsmasq.service` (gestionado con `systemctl`).
- **Archivo de Configuración:** `/etc/dnsmasq.conf` y `/etc/dnsmasq.d/`.
- **Rango Dinámico:** `192.168.0.100` a `192.168.0.249`, Máscara: `255.255.255.0`, Lease time: `8h`.
- **Gateway entregado:** `192.168.0.1`.
- **Servidores DNS entregados:**
  1. `192.168.0.120` (DNS local propio para dominios `.castelgandolfo`).
  2. `208.67.222.222` (OpenDNS para navegación web hacia Internet).
- **Leases activos:** Almacenados en `/var/lib/misc/dnsmasq.leases`.
- **Log de eventos:** `/var/log/dnsmasq.log`.

### 2.3. Resolución de Dominios Locales (Intranet)
Para que los computadores del colegio accedan a los servidores locales **sin depender de la conexión a Internet**:
- `http://castelboard.castelgandolfo`  `192.168.0.120` (CastelBoard)
- `http://estudio.castelgandolfo`  `192.168.0.120` (EduDocente Studio)
- `http://core.castelgandolfo`  `192.168.0.120` (CCG Core Admin)
- `http://vault.castelgandolfo`  `192.168.0.120` (Bóveda Digital)

### 2.4. Comandos de Administración de Red
```bash
# Ver estado del servicio DHCP / DNS
sudo systemctl status dnsmasq

# Reiniciar servicio tras modificar IPs o configuraciones
sudo systemctl restart dnsmasq

# Ver clientes que han solicitado IP en tiempo real
sudo tail -f /var/log/dnsmasq.log | grep -E "DHCPACK|DHCPREQUEST"

# Ver tabla de concesiones actuales (leases)
cat /var/lib/misc/dnsmasq.leases
```

---

## 3. Servidor Físico On-Premise (`ccg-fisico`)

### 3.1. Acceso y Usuarios del Sistema
- **Acceso por SSH:**
  ```bash
  # Desde red externa vía Tailscale:
  ssh ccgadmin@ccg-fisico.tail0e08b5.ts.net

  # Desde la red local del colegio:
  ssh ccgadmin@192.168.0.120
  ```
- **Usuarios configurados:**
  - `ccgadmin`: Cuenta administrativa para mantenimiento por SSH (con privilegios `sudo`).
  - `admin-colegio`: Cuenta de servicio sin login interactivo bajo la cual se ejecutan CastelBoard y EduDocente por seguridad.
  - `root`: Administrador supremo del sistema (ejecuta `ccg-core-admin`).

### 3.2. Estructura de Almacenamiento en Disco
El servidor cuenta con dos unidades de almacenamiento:
1. **Disco de Sistema (`/dev/mapper/ubuntu--vg-ubuntu--lv`):** 232 GB para el sistema operativo, binarios y código fuente en `/home/admin-colegio/`.
2. **Disco de Datos Masivos (`/dev/sdb1` montado en `/data`):** 220 GB dedicado exclusivamente a:
   - Entregas de alumnos: `/data/colegio/castelboard/storage/entregas/`
   - Respaldos nocturnos: `/data/colegio/backups/`

### 3.3. Servicios Systemd y Puertos

| Servicio Systemd | Puerto Local | Usuario | Directorio de Trabajo | Función |
|---|---|---|---|---|
| `castelboard.service` | `8087` | `admin-colegio` | `/home/admin-colegio/castelboard` | Portafolio digital de alumnos y casilleros |
| `edudocente.service` | `8086` | `admin-colegio` | `/home/admin-colegio/edudocente` | Generador curricular de pruebas Word con IA |
| `ccg-core-admin.service` | `9090` | `root` | `/home/admin-colegio/ccg-core-admin` | Panel de monitoreo de red y control físico |
| `nginx.service` | `80` | `www-data` | `/etc/nginx/` | Reverse Proxy local para nombres `.castelgandolfo` |
| `dnsmasq.service` | `53`, `67` UDP | `dnsmasq` | `/etc/dnsmasq.d/` | Servidor DNS local y DHCP Layer 2 |
| `tailscaled.service` | — | `root` | `/var/lib/tailscale/` | Túnel seguro y Funnel Let's Encrypt |

**Comandos de gestión:**
```bash
# Reiniciar todos los servicios del colegio
sudo systemctl restart castelboard edudocente ccg-core-admin nginx

# Ver logs en vivo de CastelBoard
sudo journalctl -u castelboard -f

# Ver logs en vivo de EduDocente Studio
sudo journalctl -u edudocente -f
```

### 3.4. CastelBoard: Portafolio y Timbre Escolar Inteligente
- **Motor:** Python 3 + FastAPI/Uvicorn, base de datos SQLite en `/home/admin-colegio/castelboard/data/castelboard.db`.
- **Mecanismo del Timbre Escolar:**
  - Bloquea automáticamente las entregas fuera del horario lectivo (08:00 a 16:30 hrs de lunes a viernes).
  - Previene que los alumnos envíen tareas desde sus casas o fuera de clase sin autorización.
- **Forzado Manual del Timbre:**
  - Si hay un evento especial o día interferiado, el administrador puede conmutar el timbre desde `CCG Core Admin` (:9090) o vía terminal:
  ```bash
  curl -s -X POST http://127.0.0.1:8087/api/admin/horarios/timbre/ejecutar-ahora -H "Content-Type: application/json" -d "{}"
  ```
- **Copia de Notas a Sofia School:**
  - CastelBoard calcula notas del 1.0 al 7.0 al 60% de exigencia (Decreto 67). El botón *Copiar Notas* copia la columna tabulada lista para pegar en el libro de clases digital oficial.

### 3.5. EduDocente Studio: Generador Curricular Asistido por IA
- **Motor:** Python 3 + python-docx + motor de inferencia curricular.
- **Catálogo Curricular:** Archivo JSON `/home/admin-colegio/edudocente/config/mineduc_oa_priorizados.json`. Contiene los Objetivos de Aprendizaje priorizados del Mineduc para Ciencias, Matemática, Lenguaje, Historia, Música y Orientación (1° Básico a 4° Medio).
- **Generación de Evaluaciones:**
  - Genera automáticamente dos documentos Word (.docx) con membrete oficial del colegio:
    1. `Evaluacion_[Asignatura]_[Curso]_Alumno.docx`
    2. `Evaluacion_[Asignatura]_[Curso]_Pauta_Profesor.docx`
  - Soporta puntajes dinámicos de 10 a 100 puntos con suma matemática exacta sin descuadres.
- **Respaldo Local (Buffer Offline $0 Costo):**
  - Si la conexión externa o las cuotas de IA se saturan, el motor conmuta automáticamente al generador estructurado local, asegurando que el docente jamás pierda su material.

### 3.6. CCG Core Admin: Panel Central de Servidor Físico
- Panel web local accesible en `http://192.168.0.120:9090` o `http://core.castelgandolfo`.
- Muestra el estado del hardware (CPU, RAM, temperatura, espacio en disco), estado de los servicios systemd, clientes conectados por DHCP y botón de sincronización inmediata del timbre escolar.

### 3.7. Tailscale Funnel: Publicación Segura hacia Internet
Para que los profesores y alumnos puedan acceder desde sus hogares sin necesidad de abrir puertos en el router escolar ni contratar IP pública fija:
- **URL CastelBoard:** `https://ccg-fisico.tail0e08b5.ts.net/`  Reenvía a `127.0.0.1:8087`.
- **URL EduDocente:** `https://ccg-fisico.tail0e08b5.ts.net:8443/`  Reenvía a `127.0.0.1:8086`.
- **URL Core Admin:** `https://ccg-fisico.tail0e08b5.ts.net:10000/`  Reenvía a `127.0.0.1:9090`.
- Certificados SSL automáticos emitidos y renovados por Let's Encrypt mediante la infraestructura de Tailscale.

**Comando de verificación o restauración de Funnels:**
```bash
sudo tailscale funnel status

# Si algún funnel se desactiva, reactivar con:
sudo tailscale funnel --https=443 http://127.0.0.1:8087
sudo tailscale funnel --https=8443 http://127.0.0.1:8086
sudo tailscale funnel --https=10000 http://127.0.0.1:9090
```

---

## 4. Servidor Dedicado Cloud / Hosting cPanel

### 4.1. Acceso a cPanel y Servidor Web LiteSpeed
- **URL Administrativa:** `https://colegiocastelgandolfo.cl:2083/`
- **Servidor Web:** LiteSpeed Web Server con PHP 8.1.
- **Ruta de Archivos:** `/home/colegioc/public_html/`
- **Montaje Local en Estación de Desarrollo:** `/home/jack/mnt/castel/` (montado vía FTPS/rclone).

### 4.2. Portal Administrativo `/admin/` (CastelRoomKeeper)
El portal interno para profesores y directivos está ubicado en `/home/colegioc/public_html/admin/`.

```
/home/colegioc/public_html/admin/
├── index.php                 # Pantalla de login unificado para docentes
├── hub.php                   # Portal central con acceso a las 4 plataformas
├── calendar.php              # Calendario de Salas de Computación (Básica y Media)
├── calendar_month_app.js     # Aplicación cliente del calendario de computación
├── biblioteca.php            # Calendario de reserva de Biblioteca escolar
├── biblioteca_app.js         # Aplicación cliente de la biblioteca
├── calendar_api.php          # Backend REST de reservas, bloqueos y correos
├── calendar_store.php        # Motor transaccional de persistencia (MySQL + JSON)
├── calendar_alerts.php       # Motor de alertas TI predictivas de inicio de clase
├── mailer.php                # Cliente SMTP nativo por socket
├── mail_config.php           # Credenciales del servidor de correo saliente
├── incidencias.php           # Libro digital de fallas de computadores
├── usuarios.php              # Administración de cuentas y contraseñas de docentes
├── documentos.php            # Repositorio institucional de reglamentos y circulares
├── logs.php                  # Visor de la bitácora forense de operaciones
└── auth.php                  # Motor de sesiones, hashes, seguridad y base de datos
```

### 4.3. Calendario de Computación (Salas Básica y Media)
- Gestiona dos laboratorios independientes:
  1. **Sala Básica:** Orientada a cursos de 1° a 8° Básico.
  2. **Sala Media:** Orientada a cursos de 1° a 4° Medio.
- **Regla de Oro:** Anticipación mínima configurable (por defecto 30 minutos antes del inicio del bloque). Previene agendamientos sobre la hora.
- **Bloqueos Automáticos:** Recreos y horarios de almuerzo están bloqueados permanentemente en la malla horaria.

### 4.4. Calendario de Biblioteca (Espacio Único)
- Gestiona la Biblioteca como un **espacio único institucional** (sin división básica/media).
- Permite planificar el plan lector, sesiones de fomento lector, investigación documental y proyecciones audiovisuales.

### 4.5. Módulos de Incidencias, Usuarios y Documentos
- **Incidencias (`incidencias.php`):** Permite a los docentes reportar equipos defectuosos, teclados dañados o problemas de red indicando la sala, bloque y equipo. Notifica automáticamente por correo al encargado de soporte.
- **Usuarios (`usuarios.php`):** Gestión de cuentas institucionales (`@colegiocastelgandolfo.cl`). Permite crear docentes, asignar rol `admin` o `docente`, y emitir códigos de recuperación.
- **Documentos (`documentos.php`):** Permite subir archivos PDF o Word con control de acceso (`Público` para toda la comunidad o `Solo Admin` para jefatura).

### 4.6. Seguridad de Sesiones, Tokens y Bitácora Inmutable
- **Tiempo de Expiración:** Sesión cerrada tras 45 minutos de inactividad o 12 horas continuas de uso máximo.
- **Anti-Brute Force:** 5 intentos fallidos consecutivos de login bloquean temporalmente la IP durante 15 minutos en `/admin/data/admin_login_locks.json`.
- **Protección CSRF:** Cada formulario y petición POST exige un token CSRF criptográfico validado en `auth.php`.
- **Privacidad y GDPR/Chile:** La dirección IP del cliente se almacena exclusivamente como un hash SHA-256 (`ip_hash`), preservando la privacidad del docente pero garantizando trazabilidad técnica ante auditorías.

---

## 5. Bases de Datos Institucionales

### 5.1. MySQL Cloud (cPanel): Conexión, Esquema y Tablas
La suite administrativa en la nube utiliza MariaDB / MySQL.
- **Autoconfiguración:** `auth.php` y `calendar_store.php` leen las variables `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` directamente desde `/home/colegioc/public_html/wp-config.php`. Si el administrador cambia la clave de base de datos en cPanel, el sistema la detecta en caliente sin necesidad de editar código.

#### Tablas Principales de la Suite

| Tabla MySQL | Función y Contenido |
|---|---|
| `reservas_por_bloque` | Registro de reservas de salas y biblioteca (fecha, bloque, sala, docente, curso, asignatura, estado). |
| `bloques_horarios` | Malla horaria oficial del colegio (Bloques 1 al 9, recreos, horas de inicio y fin). |
| `solicitudes_cambio_bloque` | Solicitudes de intercambio entre docentes cuando un bloque ya está reservado. |
| `ccg_admin_operation_log` | Bitácora inmutable de auditoría (quién inició sesión, quién reservó, quién canceló). |
| `ccg_admin_mail_delivery` | Registro de envíos de correo por SMTP y estado de entrega. |
| `ccg_calendar_mail_queue` | Cola asíncrona de correos con bloqueo pesimista `GET_LOCK`. |

### 5.2. Mecanismo de Fallback Resiliente a Archivos JSON
Si la base de datos MySQL sufriera una saturación o caída:
- El motor `calendar_store.php` conmuta automáticamente a almacenamiento JSON en `/admin/data/` (`authorized_emails.json`, `admin_operation.log`, etc.).
- Las interfaces de usuario continúan funcionando sin arrojar errores fatales HTTP 500 al cuerpo docente.

### 5.3. SQLite WAL en Servidor Físico
CastelBoard utiliza SQLite con modo **WAL (Write-Ahead Logging)** habilitado:
- Archivo: `/home/admin-colegio/castelboard/data/castelboard.db`.
- Permite lecturas y escrituras concurrentes de cientos de estudiantes simultáneos sin bloqueos de base de datos.

### 5.4. Procedimientos de Respaldo y Restauración
#### Respaldo de MySQL (cPanel / Servidor Cloud)
```bash
# Exportar base de datos completa desde terminal SSH de cPanel:
mysqldump -u colegio_rene2018 -p colegio_colegio2018 > /home/colegioc/backup_colegio_$(date +%Y%m%d).sql

# Restaurar base de datos:
mysql -u colegio_rene2018 -p colegio_colegio2018 < /home/colegioc/backup_colegio_YYYYMMDD.sql
```

#### Respaldo de SQLite y Archivos en Servidor Físico
```bash
# Respaldo atómico de SQLite con comando nativo:
sqlite3 /home/admin-colegio/castelboard/data/castelboard.db ".backup '/data/colegio/backups/castelboard_$(date +%Y%m%d).db'"

# Respaldo de entregas de estudiantes:
rsync -av /data/colegio/castelboard/storage/entregas/ /data/colegio/backups/entregas/
```

---

## 6. Sistema de Correos Automáticos y Notificaciones SMTP

### 6.1. Configuración de Envío SMTP (`mail_config.php`)
Ubicación en el servidor: `/home/colegioc/public_html/admin/mail_config.php`.  
*(Este archivo está protegido por `.htaccess` para que nadie pueda descargarlo desde un navegador).*

```php
<?php
return array(
    'host'       => 'mail.colegiocastelgandolfo.cl',
    'port'       => 465,
    'secure'     => 'ssl',
    'username'   => 'avisos-web@colegiocastelgandolfo.cl',
    'password'   => 'Contraseña_Configurada_En_CPanel',
    'from_email' => 'avisos-web@colegiocastelgandolfo.cl',
    'from_name'  => 'Colegio Castelgandolfo',
    'reply_to'   => 'avisos@colegiocastelgandolfo.cl',
);
```

### 6.2. Motor de Envío Asíncrono y Cola Persistente
Para evitar que un docente espere 5 segundos a que responda el servidor SMTP al guardar una reserva:
1. El backend guarda la reserva en MySQL inmediatamente y deposita el correo en la tabla `ccg_calendar_mail_queue` con estado `queued`.
2. El navegador dispara una llamada en segundo plano (`action=process_mail_queue`).
3. El worker adquiere un cerrojo exclusivo en MySQL: `SELECT GET_LOCK('ccg_calendar_mail_queue_worker', 0)`.
4. Si dos profesores reservan al mismo segundo, solo un worker despacha los correos, evitando duplicaciones.
5. Si el servidor de correo estuviera caído, el trabajo permanece en cola y se reintenta automáticamente hasta 5 veces.

### 6.3. Catálogo de Notificaciones Automáticas
El sistema genera correos en las siguientes situaciones:
1. **Confirmación de Reserva:** Enviada al docente al agendar una sala de computación o biblioteca.
2. **Solicitud de Intercambio de Sala:** Si el Profesor A necesita un bloque ya ocupado por el Profesor B, el sistema envía una solicitud formal al correo del Profesor B con botones directos para aceptar o declinar.
3. **Respuesta a Solicitud de Sala:** Notifica al solicitante si su petición fue aprobada o rechazada.
4. **Reporte de Incidencia Técnica:** Notifica de inmediato al equipo de soporte informático con los detalles del equipo con fallas.
5. **Recuperación de Contraseña:** Genera un correo formal con un código OTP de 6 dígitos en fuente monospace con validez de 60 minutos.

### 6.4. Avisos Predictivos de Inicio de Clase (`calendar_alerts.php`)
- El sistema cuenta con un motor predictivo que revisa las clases del día.
- **Función:** Envía un correo de aviso al encargado de informática 10 minutos antes de que empiece una clase agendada, indicando sala, bloque, curso y profesor responsable.
- **Configuración:** Se activa o desactiva desde `/admin/correo-avisos.php`.

### 6.5. Diagnóstico y Pruebas en Vivo (`mail-test-calendar.php`)
Si los profesores reportan que no reciben avisos:
1. Iniciar sesión como administrador en `https://colegiocastelgandolfo.cl/admin/`.
2. Ingresar a `https://colegiocastelgandolfo.cl/admin/mail-test-calendar.php`.
3. Ingresar un correo de prueba y presionar **Enviar correo de prueba**.
4. La herramienta imprimirá la conversación SMTP exacta socket por socket (`EHLO`, `AUTH LOGIN`, `MAIL FROM`, etc.) identificando si el fallo es de credenciales, bloqueo de puerto o DNS.

---

## 7. Sistemas Cron, Timers y Procesos Automáticos

### 7.1. Servidor Dedicado Cloud (cPanel)
- **Procesador de Cola de Correo (Cron de respaldo):**  
  Para asegurar que la cola de correos se procese incluso si no hay docentes navegando en el portal:
  ```bash
  # Cron en cPanel cada 5 minutos:
  */5 * * * * /usr/bin/curl -s -X POST https://www.colegiocastelgandolfo.cl/admin/calendar_api.php?action=process_mail_queue >/dev/null 2>&1
  ```
- **Limpieza de Tokens y Bloqueos:**
  `auth.php` purga automáticamente tokens con más de 60 minutos de antigüedad durante las peticiones normales.

### 7.2. Servidor Físico On-Premise (`ccg-fisico`)
El servidor físico cuenta con timers de systemd nativos (`systemctl list-timers`):
1. **`star-data-backup.timer` (03:16 UTC diariamente):**
   - Ejecuta respaldo nocturno de bases de datos, SQLite y entregas.
2. **`logrotate.timer` (00:55 UTC diariamente):**
   - Rota los logs de Nginx, dnsmasq y systemd para evitar que el disco se llene.
3. **`fstrim.timer` (Lunes 00:36 UTC):**
   - Optimiza y descarta bloques libres en discos de estado sólido (SSD).

---

## 8. Procedimientos Operativos Estándar (Runbooks)

### Caso A: Corte de Energía y Reinicio del Servidor Físico
**Síntoma:** El colegio sufrió un corte de luz y los computadores no tienen red ni abren CastelBoard.  
**Solución:**
1. Verificar que el servidor físico del rack esté encendido (botón de encendido en el gabinete).
2. Conectar un cable de red o acceder por SSH:
   ```bash
   ssh ccgadmin@192.168.0.120
   ```
3. Verificar el estado de los servicios:
   ```bash
   sudo systemctl status dnsmasq nginx castelboard edudocente ccg-core-admin
   ```
4. Si alguno aparece como `inactive` o `failed`, iniciarlo:
   ```bash
   sudo systemctl restart dnsmasq nginx castelboard edudocente ccg-core-admin
   ```
5. Comprobar que los clientes vuelvan a recibir IP solicitando renovación en un PC (`ipconfig /renew` en Windows).

---

### Caso B: Caída del Enlace de Internet en el Colegio (Modo Offline)
**Síntoma:** El proveedor de fibra óptica escolar se cayó; no hay Internet hacia el exterior.  
**Solución y Comportamiento:**
1. **La Intranet Sigue Operativa:** Como `dnsmasq` y `nginx` están dentro del servidor físico en el colegio, los alumnos y docentes **pueden seguir usando CastelBoard y EduDocente** ingresando por los dominios locales:
   - `http://castelboard.castelgandolfo`
   - `http://estudio.castelgandolfo`
2. Las evaluaciones generadas en EduDocente funcionarán bajo el **Smart Buffer Offline**, creando los archivos Word (.docx) localmente sin depender de APIs en la nube.
3. Lo único inaccesible durante la caída de Internet externo será el portal web cloud (`colegiocastelgandolfo.cl/admin/`).

---

### Caso C: Desbloqueo y Reseteo de Contraseña de un Docente
**Síntoma:** Un profesor olvidó su contraseña o fue bloqueado por múltiples intentos erróneos.  
**Solución:**
1. Ingresar a `https://www.colegiocastelgandolfo.cl/admin/usuarios.php` con cuenta de administrador.
2. Localizar al docente en la tabla de usuarios.
3. Presionar **Editar** o **Restablecer Clave**.
4. Se generará un código de verificación que llegará al correo del profesor, o el administrador puede asignar una clave temporal directamente.
5. **Si el usuario quedó bloqueado por IP:** Borrar el archivo de bloqueo en el servidor:
   ```bash
   rm /home/colegioc/public_html/admin/data/admin_login_locks.json
   ```

---

### Caso D: Falla en la Entrega de Correos Institucionales
**Síntoma:** Los profesores afirman que no reciben correos de confirmación.  
**Solución:**
1. Abrir `https://www.colegiocastelgandolfo.cl/admin/mail-test-calendar.php` y enviar un correo de prueba.
2. Si arroja `535 Incorrect authentication data`:
   - Ingresar a cPanel  **Cuentas de Correo Electrónico**.
   - Verificar que la cuenta `avisos-web@colegiocastelgandolfo.cl` exista y su cuota de buzón no esté llena (100%).
   - Si se cambió la contraseña del correo en cPanel, actualizarla de inmediato en `/home/colegioc/public_html/admin/mail_config.php`.

---

### Caso E: Bloqueo de Horarios por Actos Cívicos o Mantenimiento
**Síntoma:** Se requiere cerrar la sala de computación o biblioteca por una semana por reparaciones.  
**Solución:**
1. Ingresar a `calendar.php` o `biblioteca.php`.
2. Presionar el botón **Mantenimiento / Bloqueo** en la barra superior.
3. Seleccionar el rango de fechas y la sala afectada.
4. El sistema cambiará el estado de los bloques a `mantenimiento` (color ámbar) impidiendo que cualquier docente agende en esos días.

---

### Caso F: Despliegue de Actualizaciones de Código a Producción
**Síntoma:** Se realizaron mejoras de código y deben subirse al servidor cPanel.  
**Solución:**
1. En la estación de trabajo local (`Nova`), los archivos están montados en `/home/jack/mnt/castel/`.
2. Copiar los archivos modificados:
   ```bash
   cp admin/archivo_modificado.php /home/jack/mnt/castel/admin/
   ```
3. Verificar en producción con `curl -I`:
   ```bash
   curl -I https://www.colegiocastelgandolfo.cl/admin/index.php
   ```
4. Confirmar que responde `HTTP/2 200`.

---

## 9. Directorio Maestro de Archivos, Rutas y Puertos

### En el Servidor Dedicado Cloud (`colegiocastelgandolfo.cl`)
- **Directorio Web Principal:** `/home/colegioc/public_html/`
- **Suite Administrativa:** `/home/colegioc/public_html/admin/`
- **Configuración de Correo:** `/home/colegioc/public_html/admin/mail_config.php`
- **Configuración de Base de Datos:** `/home/colegioc/public_html/wp-config.php`
- **Datos y Respaldos JSON:** `/home/colegioc/public_html/admin/data/`
- **Página Acceso Docentes:** `/home/colegioc/public_html/acceso-docentes/index.html`

### En el Servidor Físico On-Premise (`ccg-fisico` / `192.168.0.120`)
- **Código CastelBoard:** `/home/admin-colegio/castelboard/`
- **Base de Datos CastelBoard:** `/home/admin-colegio/castelboard/data/castelboard.db`
- **Almacenamiento de Entregas Alumnos:** `/data/colegio/castelboard/storage/entregas/`
- **Código EduDocente Studio:** `/home/admin-colegio/edudocente/`
- **Catálogo Curricular Mineduc:** `/home/admin-colegio/edudocente/config/mineduc_oa_priorizados.json`
- **Código CCG Core Admin:** `/home/admin-colegio/ccg-core-admin/`
- **Configuración Nginx:** `/etc/nginx/sites-available/` y `/etc/nginx/sites-enabled/`
- **Configuración DHCP / DNS:** `/etc/dnsmasq.conf` y `/etc/dnsmasq.d/`
- **Log DHCP / DNS:** `/var/log/dnsmasq.log`
- **Servicios Systemd:** `/etc/systemd/system/castelboard.service`, `edudocente.service`, `ccg-core-admin.service`

---
> **Fin del Manual Maestro de Continuidad Operativa.**  
> *Consérvese este documento de forma segura e inmutable en el repositorio de administración del establecimiento.*
