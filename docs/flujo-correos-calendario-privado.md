# Flujo de correos del calendario privado

Fecha: 2026-04-21

## Objetivo

Que el calendario de la sala de computación no solo guarde reservas, sino que además:

- avise por correo cuando un usuario reserva o edita un día
- avise por correo al propietario cuando otro docente solicita un cambio
- avise por correo al solicitante cuando la solicitud es aprobada o rechazada

## Archivos involucrados

- `Z:\admin\calendar_api.php`
- `Z:\admin\calendar_app.js`
- `Z:\data\authorized_emails.json`
- `Z:\data\calendar_store.json`

## Cómo quedó implementado

### 1. Reserva o edición propia

Cuando un usuario guarda o libera un día desde el calendario:

- el servidor actualiza la reserva central
- luego intenta enviar correo al mismo usuario que hizo la acción

Casos cubiertos:

- crear reserva
- actualizar reserva
- liberar reserva

### 2. Override desde admin/directivo/coordinación

Si un usuario con permisos altos modifica una reserva ajena:

- se guarda la modificación
- se registra auditoría
- además se intenta avisar por correo al propietario original

### 3. Solicitud de cambio sobre reserva ajena

Si un profesor intenta modificar un día que ya pertenece a otro:

- no puede sobrescribirlo
- el sistema muestra advertencia
- puede enviar solicitud de cambio
- antes de guardar, el navegador pregunta si desea avisar por correo

Si acepta:

- se guarda la solicitud
- se envía correo al propietario actual

Si cancela:

- la solicitud se guarda igual
- pero sin correo

### 4. Respuesta de solicitud

Cuando el propietario o un responsable del panel aprueba o rechaza:

- la solicitud cambia de estado
- si corresponde, la reserva se actualiza
- el sistema pregunta si desea avisar por correo al solicitante

Si acepta:

- se envía correo al solicitante con el resultado

## Nota importante

El correo actualmente sirve como:

- aviso
- respaldo
- notificación operativa

Pero la acción de aprobar o rechazar **no se hace desde el correo**.

La aprobación sigue ocurriendo dentro del panel:

- `https://www.colegiocastelgandolfo.cl/admin/calendar.php`

## Por qué se dejó así

Porque es una primera versión segura y clara:

- no depende de links sensibles por mail
- no expone tokens públicos todavía
- mantiene la decisión dentro del entorno autenticado del panel

## Mejora futura posible

Si más adelante se quiere ir un paso más allá, se puede implementar:

- enlace único por correo para aprobar o rechazar
- con token firmado
- con expiración
- con revalidación adicional del usuario

Pero eso requiere una segunda capa de seguridad.

## Método de envío actual

El sistema intenta enviar con `mail()` de PHP y cabeceras tipo:

- remitente por defecto: `avisos-web@colegiocastelgandolfo.cl`

Esto puede mejorarse después si se quiere:

- SMTP autenticado
- logs de entrega
- cola de correo

## Recomendación

Probar en producción real con una reserva y una solicitud entre dos cuentas institucionales, por ejemplo:

- `pavendano@...`
- `rreyes@...`

para confirmar:

- que el hosting envía correctamente
- que el correo no cae en spam
- que el asunto y el texto sean comprensibles para el equipo del colegio
