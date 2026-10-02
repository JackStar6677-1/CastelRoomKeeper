<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendar_alerts.php';

admin_bootstrap_session();
admin_require_site_admin();

$message = '';
$error = '';
$settings = calendar_alert_settings_read();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_validate_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión expiró. Recarga la página e inténtalo nuevamente.';
    } else {
        $candidate = calendar_alert_settings_normalize(array(
            'enabled' => !empty($_POST['enabled']),
            'recipient_email' => $_POST['recipient_email'] ?? '',
            'lead_minutes' => $_POST['lead_minutes'] ?? 10,
        ));
        if ($candidate['enabled'] && $candidate['recipient_email'] === '') {
            $error = 'Indica un correo institucional o personal válido para recibir los avisos TI.';
        } elseif (!calendar_alert_settings_save($candidate)) {
            $error = 'No se pudo guardar la configuración de avisos.';
        } else {
            $settings = $candidate;
            admin_log_operation('calendar_class_alert', 'settings_updated', 'ok', array(
                'enabled' => $settings['enabled'],
                'recipient' => $settings['recipient_email'],
                'lead_minutes' => $settings['lead_minutes'],
            ), 'Configuración de avisos TI actualizada desde el panel.');
            $message = 'Configuración guardada. Los próximos avisos usarán estos valores.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Avisos TI de clases | CCG Admin</title>
    <link rel="stylesheet" href="admin-responsive.css">
    <style>
        body{font-family:Outfit,system-ui,sans-serif;margin:0;min-height:100vh;background:#edf4f6;color:#17324d}.main{max-width:760px;margin:0 auto;padding:clamp(20px,5vw,48px) 18px}.panel{background:#fff;border:1px solid #c5dbe2;border-radius:8px;padding:24px;box-shadow:0 12px 28px rgba(20,60,80,.1)}h1{margin-top:0}.lead{color:#48657a;line-height:1.55}.field{display:grid;gap:7px;margin:18px 0;font-weight:700}.field input{box-sizing:border-box;width:100%;padding:11px 12px;border:1px solid #9fbcc8;border-radius:6px;font:inherit}.toggle{display:flex;gap:10px;align-items:center;font-weight:700}.btn{border:0;border-radius:6px;background:#1d8158;color:#fff;padding:11px 16px;font:inherit;font-weight:800;cursor:pointer}.back{display:inline-block;margin-bottom:18px;color:#1d5c93;font-weight:700;text-decoration:none}.notice{padding:12px 14px;border-radius:6px;margin:16px 0;line-height:1.45}.ok{background:#e5f6e9;color:#1d6130;border:1px solid #9ed7aa}.error{background:#fff0f0;color:#9a2020;border:1px solid #f0aaaa}.hint{font-size:.9rem;color:#537085;line-height:1.45}
    </style>
</head>
<body>
    <main class="main">
        <a class="back" href="calendar.php">Volver al calendario</a>
        <section class="panel">
            <h1>Avisos TI antes de una clase</h1>
            <p class="lead">El cron existente revisa cada minuto las reservas del día. Cuando una clase entra en el margen configurado, deja un único aviso en la cola de correo; no crea un proceso permanente ni duplica mensajes.</p>
            <?php if ($message): ?><div class="notice ok"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <label class="toggle"><input type="checkbox" name="enabled" value="1"<?php echo !empty($settings['enabled']) ? ' checked' : ''; ?>> Activar avisos de inicio de clase</label>
                <label class="field">Correo responsable de TI
                    <input type="email" name="recipient_email" value="<?php echo htmlspecialchars($settings['recipient_email'], ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label class="field">Minutos de anticipación
                    <input type="number" name="lead_minutes" min="1" max="60" value="<?php echo (int) $settings['lead_minutes']; ?>" required>
                </label>
                <p class="hint">El aviso indica sala, bloque, hora de inicio, actividad, curso y correo del docente responsable. Cambia aquí el correo cuando cambie la persona encargada de TI.</p>
                <button class="btn" type="submit">Guardar avisos TI</button>
            </form>
        </section>
    </main>
</body>
</html>
