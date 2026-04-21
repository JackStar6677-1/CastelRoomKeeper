# CastelRoomKeeper

Calendario privado para laboratorios o salas de computación, pensado para colegios, equipos TI y coordinación académica.

El proyecto nació desde una necesidad real: organizar reservas por sala, evitar que un docente le pise el bloque a otro y dejar trazabilidad cuando alguien solicita cambiar una reserva ya tomada.

## Qué resuelve

- Reservas por sala y fecha con propietario real.
- Bloqueo de edición sobre reservas ajenas.
- Solicitudes de cambio en vez de sobreescritura directa.
- Flujo de aprobación o rechazo por parte del dueño o de roles altos.
- Notificaciones por correo vía SMTP.
- Historial y auditoría básica de cambios.
- Base inicial simple con almacenamiento JSON y bloqueo de archivo.

## Casos de uso

- Sala de computación de Básica.
- Sala de computación de Media.
- Laboratorios compartidos.
- Espacios con alta demanda entre docentes o coordinadores.

## Stack actual

- `PHP`
- `JavaScript`
- `JSON` como almacenamiento inicial
- `SMTP` para avisos automáticos

## Estructura

```text
CastelRoomKeeper/
├─ admin/
│  ├─ auth.php
│  ├─ calendar.php
│  ├─ calendar_api.php
│  ├─ calendar_app.js
│  ├─ calendar_store.php
│  ├─ mailer.php
│  └─ mail_config.example.php
├─ data/
│  ├─ authorized_emails.example.json
│  └─ calendar_store.example.json
├─ docs/
│  ├─ diseno-calendario-multiusuario-y-bloqueos.md
│  └─ flujo-correos-calendario-privado.md
├─ .gitignore
└─ README.md
```

## Cómo funciona

1. Un usuario autorizado entra al panel.
2. Reserva un día para una sala.
3. La reserva queda asociada a su cuenta.
4. Otro usuario no puede reemplazarla directamente.
5. Si necesita ese bloque, crea una solicitud de cambio.
6. El dueño original o un rol superior aprueba o rechaza.
7. El sistema puede enviar correos automáticos según el evento.

## Roles pensados

- `profesor`
- `coordinacion`
- `directivo`
- `admin`

## Qué incluye el repo

- La base del calendario privado.
- La lógica de propiedad de reserva.
- El flujo de solicitudes de cambio.
- El envío de correos por SMTP.
- Archivos de ejemplo para configuración y usuarios.

## Qué no incluye

- Contraseñas reales.
- Correos institucionales reales.
- Branding final de una institución específica.
- Configuración de producción.

## Puesta en marcha

1. Copia `admin/mail_config.example.php` a `admin/mail_config.php`.
2. Completa tu servidor SMTP.
3. Copia `data/authorized_emails.example.json` a `data/authorized_emails.json`.
4. Ajusta textos, logo y rutas del panel.
5. Publica el contenido en un entorno PHP.

## Evolución recomendada

La versión actual es ideal para un primer despliegue interno. Cuando el uso crezca, la mejora natural es migrar el almacenamiento desde JSON a `MySQL` o `MariaDB` para obtener:

- concurrencia más sólida
- consultas más rápidas
- historial más robusto
- administración multiusuario más confiable

## Roadmap sugerido

- Migración a base de datos.
- Panel de administración de usuarios.
- Calendario por bloques horarios.
- Exportación institucional.
- Tokens seguros para aprobar cambios desde correo.
- Integración con calendario académico y feriados.

## Licencia

MIT
