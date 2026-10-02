<?php
if (!function_exists('ccg_admin_nav_class')) {
    function ccg_admin_nav_class($script, $current)
    {
        return $script === $current ? 'nav-link is-active' : 'nav-link';
    }
}
$ccg_current_script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'editor.php');
$ccg_csrf = isset($csrf_token) ? $csrf_token : admin_csrf_token();
$ccg_current_user = function_exists('admin_current_user') ? admin_current_user() : null;
$ccg_current_role = function_exists('admin_user_role') ? admin_user_role($ccg_current_user) : 'profesor';
$ccg_can_manage_site = function_exists('admin_user_can_manage_site') ? admin_user_can_manage_site($ccg_current_user) : false;
$ccg_can_manage_users = in_array($ccg_current_role, array('admin', 'directivo'), true);
?>
<div class="sidebar">
    <div class="sidebar-top-bar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <a href="hub.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;" title="Volver al Hub Digital">
            <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="CCG" style="height:36px;width:auto;background:#fff;padding:2px 5px;border-radius:7px;">
            <div>
                <h2 style="margin:0;font-size:1.05rem;line-height:1.15;color:#fff;">CCG Admin</h2>
                <span style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:#7bc4ff;font-weight:700;">Panel Central</span>
            </div>
        </a>
        <button type="button" class="sidebar-toggle-btn" onclick="document.querySelector('.sidebar').classList.toggle('is-open')" aria-label="Abrir menú de navegación" style="display:none;">
            ☰ Menú
        </button>
    </div>

    <div class="sidebar-nav-content">
        <p class="sidebar-user" style="margin-bottom:10px;">Hola, <?php echo htmlspecialchars(isset($_SESSION['admin_email']) ? $_SESSION['admin_email'] : '', ENT_QUOTES, 'UTF-8'); ?> <span style="display:inline-block;padding:2px 7px;border-radius:999px;background:rgba(214,170,67,0.22);color:#fef08a;font-size:0.68rem;font-weight:800;text-transform:uppercase;"><?php echo htmlspecialchars($ccg_current_role, ENT_QUOTES, 'UTF-8'); ?></span></p>
        <hr class="sidebar-divider">

        <!-- Plataformas del Ecosistema Escolar -->
        <a href="hub.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('hub.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">🏛️ Ecosistema Digital (Hub)</a>
        <a href="calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">📅 Calendario sala computación</a>
        <a href="http://castelboard.castelgandolfo" target="_blank" rel="noopener" class="nav-link" style="border-left:3px solid #3b82f6;" title="CastelBoard · Casillero digital y recepción de trabajos">🎒 Portafolio (CastelBoard) ↗</a>
        <a href="http://estudio.castelgandolfo" target="_blank" rel="noopener" class="nav-link" style="border-left:3px solid #10b981;" title="EduDocente Studio · Generador de evaluaciones Word con IA">🪄 Evaluaciones IA (EduDocente) ↗</a>

        <hr class="sidebar-divider" style="margin:12px 0;">

        <!-- Gestión y Auditoría Administrativa -->
        <?php if ($ccg_can_manage_site): ?>
        <a href="logs.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('logs.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>" style="font-weight:700;">📜 Bitácora & Logs del Sistema</a>
        <a href="documentos.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('documentos.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">📁 Documentos oficiales web</a>
        <?php endif; ?>
        <?php if ($ccg_can_manage_users): ?>
        <a href="usuarios.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('usuarios.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">👥 Gestión de usuarios</a>
        <?php endif; ?>

        <?php if ($ccg_can_manage_site): ?>
        <a href="editor.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('editor.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">⚙️ Configuración general</a>
        <a href="security.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('security.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">🔒 Seguridad y accesos</a>
        <a href="correo-avisos.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('correo-avisos.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">📧 Correo y avisos institucionales</a>
        <?php if (function_exists('admin_maintenance_tools_enabled') && admin_maintenance_tools_enabled()): ?>
        <a href="mail-test-calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('mail-test-calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">📨 Prueba de envío SMTP</a>
        <a href="sql.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('sql.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">🛠️ Mantenimiento MySQL</a>
        <?php endif; ?>
        <a href="/" class="nav-link" target="_blank" rel="noopener">🌐 Sitio público ↗</a>
        <a href="/wp-admin/" class="nav-link" target="_blank" rel="noopener">📝 WordPress — administración ↗</a>
        <form class="nav-form" method="POST" action="rebuild.php" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($ccg_csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="nav-link btn btn-success" style="border-radius:8px;padding:9px 12px;font-weight:700;color:#fff;">Publicar cambios web</button>
        </form>
        <?php endif; ?>
        <a href="index.php?logout=1" class="nav-link nav-link--muted" style="margin-top:18px;color:#ffc9c9;">Cerrar sesión</a>
    </div>
</div>
