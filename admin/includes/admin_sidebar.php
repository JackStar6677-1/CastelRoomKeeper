<?php
if (!function_exists('ccg_admin_nav_class')) {
    function ccg_admin_nav_class($script, $current)
    {
        return $script === $current ? 'nav-link is-active' : 'nav-link';
    }
}
$ccg_current_script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'editor.php');
$ccg_csrf = isset($csrf_token) ? $csrf_token : admin_csrf_token();
?>
<div class="sidebar">
    <h2>CCG Admin</h2>
    <p class="sidebar-user">Hola, <?php echo htmlspecialchars(isset($_SESSION['admin_email']) ? $_SESSION['admin_email'] : '', ENT_QUOTES, 'UTF-8'); ?></p>
    <hr class="sidebar-divider">
    <a href="editor.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('editor.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Configuración general</a>
    <a href="calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Calendario sala computación</a>
    <a href="correo-avisos.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('correo-avisos.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Correo y avisos institucionales</a>
    <a href="mail-test-calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('mail-test-calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Prueba de envío SMTP</a>
    <a href="/app/" class="nav-link" target="_blank" rel="noopener">Sitio público /app</a>
    <a href="/wp-admin/" class="nav-link" target="_blank" rel="noopener">WordPress — administración</a>
    <form class="nav-form" method="POST" action="rebuild.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($ccg_csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="nav-link btn btn-success">Publicar cambios</button>
    </form>
    <a href="index.php?logout=1" class="nav-link nav-link--muted">Cerrar sesión</a>
</div>
