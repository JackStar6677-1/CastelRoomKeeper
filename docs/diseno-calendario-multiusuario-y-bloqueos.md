# Diseño: Calendario Multiusuario y Bloqueos

Fecha: 2026-04-21

## Estado real hoy

El calendario privado actual:

- vive en `Z:\admin\calendar.php`
- requiere sesión del panel
- guarda en `localStorage`

Eso sirve como maqueta funcional, pero **no sirve todavía como sistema multiusuario serio**.

## Problema principal

Si varios profesores usan el calendario al mismo tiempo, con guardado local:

- cada uno ve una copia distinta
- no existe dueño real de una reserva
- no existe bloqueo de edición
- no hay historial central
- cualquiera puede “pisar” cambios si luego se migra sin reglas

## Implementación correcta recomendada

### 1. Fuente de verdad central

La fuente de verdad debe ser `MySQL/MariaDB`, no `localStorage`.

El JSON solo puede quedar como:

- respaldo
- importación/exportación
- migración

No como almacenamiento principal.

### 2. Usuarios

Cada docente debe autenticarse con su correo institucional.

Campos mínimos del usuario:

- `id`
- `email`
- `nombre`
- `rol`
- `password_hash`
- `activo`
- `created_at`
- `updated_at`

Roles sugeridos:

- `profesor`
- `coordinacion`
- `directivo`
- `admin`

### 3. Reservas

Cada día reservado debe quedar vinculado a un propietario.

Campos mínimos de una reserva:

- `id`
- `calendar_date`
- `room_code`
- `semester_code`
- `status`
- `owner_user_id`
- `responsable_label`
- `notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`
- `version`
- `is_locked`

### 4. Regla dura de propiedad

Si el profesor X crea una reserva para el día R:

- el profesor X puede editarla
- el profesor X puede liberarla
- otro profesor **no** puede sobrescribirla directamente

Otro profesor solo puede:

- verla
- solicitar cambio
- pedir liberación

### 5. Flujo correcto para modificar una reserva ajena

Si el profesor Y quiere modificar una reserva creada por el profesor X:

1. el sistema detecta que `owner_user_id != usuario_actual`
2. se bloquea la edición directa
3. se crea una `solicitud de cambio`
4. el profesor X recibe aviso
5. el profesor X aprueba o rechaza
6. si aprueba, la reserva se actualiza

Alternativa por rol:

- `admin` o `directivo` puede forzar el cambio
- pero el sistema igual debe dejar auditoría

### 6. Tabla de solicitudes

Debe existir una tabla separada para las solicitudes de cambio.

Campos sugeridos:

- `id`
- `reservation_id`
- `requested_by_user_id`
- `owner_user_id`
- `requested_status`
- `requested_responsable_label`
- `requested_notes`
- `reason`
- `approval_status`
- `approved_by_user_id`
- `approved_at`
- `created_at`

Estados:

- `pendiente`
- `aprobada`
- `rechazada`
- `cancelada`

### 7. Auditoría

Toda modificación debe quedar registrada.

Tabla sugerida: `calendar_audit_log`

Campos:

- `id`
- `reservation_id`
- `action_type`
- `performed_by_user_id`
- `old_payload`
- `new_payload`
- `created_at`

Acciones típicas:

- `create`
- `update`
- `delete`
- `request_change`
- `approve_change`
- `reject_change`
- `force_override`

## Política recomendada

### Profesor

- puede crear reserva en días libres
- puede editar sus propias reservas
- no puede editar reservas ajenas
- puede pedir cambio sobre reservas ajenas

### Coordinación

- puede ver todo
- puede aprobar o mediar cambios
- puede bloquear jornadas

### Directivo / Admin

- puede forzar cambios
- puede liberar reservas
- puede corregir errores
- todo con auditoría obligatoria

## Protección contra choques simultáneos

Además del dueño, conviene usar `version` o `updated_at` para control de concurrencia.

Flujo:

1. el usuario abre la reserva
2. el formulario guarda la versión actual
3. al guardar, el sistema compara versión
4. si cambió entre medio, rechaza el guardado

Eso evita que dos sesiones distintas guarden una reserva vieja por encima de una nueva.

## Conclusión

Si se quiere que “el profesor Y no pueda sacar al profesor X del día R”, la implementación correcta es:

- reserva con dueño
- bloqueo por propietario
- solicitudes de cambio
- aprobación del dueño o de un administrador
- auditoría
- almacenamiento central en MySQL

Sin eso, cualquier solución basada solo en navegador o JSON local queda débil.
