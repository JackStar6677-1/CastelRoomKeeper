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
<style>
.sidebar-section-title {
    font-size: 0.64rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: #7bc4ff;
    margin: 14px 0 6px 8px;
    opacity: 0.9;
}
.sidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 2px;
    font-size: 0.83rem;
    color: #e2e8f0;
    text-decoration: none;
    transition: all 0.15s ease;
    margin-bottom: 2px;
    border-left: 2px solid transparent;
}
.sidebar .nav-link:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}
.sidebar .nav-link.is-active {
    background: rgba(37, 99, 235, 0.28) !important;
    color: #93c5fd !important;
    font-weight: 700 !important;
    border-left: 3px solid #3b82f6 !important;
}
.sidebar-badge-pill {
    margin-left: auto;
    font-size: 0.65rem;
    font-weight: 800;
    padding: 2px 6px;
    border-radius: 2px;
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
    border: 1px solid rgba(59, 130, 246, 0.3);
}
</style>

<div class="sidebar">
    <div class="sidebar-top-bar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <a href="hub.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;" title="Volver al Hub Digital">
            <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="CCG" style="height:34px;width:auto;background:#fff;padding:2px 4px;border-radius:2px;">
            <div>
                <h2 style="margin:0;font-size:1.02rem;line-height:1.15;color:#fff;font-weight:800;letter-spacing:-0.01em;">CCG Admin</h2>
                <span style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:#7bc4ff;font-weight:700;">Panel Central</span>
            </div>
        </a>
        <button type="button" class="sidebar-toggle-btn" onclick="document.querySelector('.sidebar').classList.toggle('is-open')" aria-label="Abrir menú de navegación" style="display:none;">
            Menú
        </button>
    </div>

    <div class="sidebar-nav-content">
        <p class="sidebar-user" style="margin-bottom:8px;">Usuario: <?php echo htmlspecialchars(isset($_SESSION['admin_email']) ? $_SESSION['admin_email'] : '', ENT_QUOTES, 'UTF-8'); ?> <span style="display:inline-block;padding:2px 6px;border-radius:2px;background:rgba(214,170,67,0.22);color:#fef08a;font-size:0.68rem;font-weight:800;text-transform:uppercase;"><?php echo htmlspecialchars($ccg_current_role, ENT_QUOTES, 'UTF-8'); ?></span></p>

        <!-- 1. PLATAFORMAS EDUCATIVAS -->
        <div class="sidebar-section-title">Plataformas Educativas</div>
        <a href="hub.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('hub.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Ecosistema Digital (Hub)</a>
        <a href="calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Salas de Computación (Básica / Media)</a>
        <a href="biblioteca.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('biblioteca.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>" style="border-left:3px solid #143D2B;" title="Biblioteca · Espacio Único">Biblioteca (Espacio Único)</a>
        <a href="sso_jump.php?target=castelboard" target="_blank" rel="noopener" class="nav-link" style="border-left:3px solid #3b82f6;" title="CastelBoard · Casilleros y Entregas">Portafolio Escolar (CastelBoard) ↗</a>
        <a href="https://ccg-fisico.tail0e08b5.ts.net:8443/" target="_blank" rel="noopener" class="nav-link" style="border-left:3px solid #10b981;" title="EduDocente Studio · Generador Curricular">Evaluaciones Oficiales (EduDocente) ↗</a>

        <!-- 2. INFRAESTRUCTURA & SERVIDOR -->
        <div class="sidebar-section-title">Infraestructura & Servidor</div>
        <a href="https://ccg-fisico.tail0e08b5.ts.net:10000/" target="_blank" rel="noopener" class="nav-link" style="border-left:3px solid #f59e0b;background:rgba(245,158,11,0.08);" title="CCG Core Admin · Control de bajo nivel del servidor físico">Servidor Físico (CCG Core Admin) ↗</a>
        <?php if ($ccg_can_manage_site): ?>
        <a href="logs.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('logs.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Bitácora de Auditoría (Logs)</a>
        <?php endif; ?>

        <!-- 3. GESTIÓN INSTITUCIONAL & DOCENTE -->
        <div class="sidebar-section-title">Gestión Institucional</div>
        <?php if ($ccg_can_manage_site): ?>
        <a href="documentos.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('documentos.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Documentos Oficiales Web</a>
        <?php endif; ?>
        <?php if ($ccg_can_manage_users): ?>
        <a href="usuarios.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('usuarios.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Gestión de Usuarios</a>
        <?php endif; ?>
        <?php if ($ccg_can_manage_site): ?>
        <a href="correo-avisos.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('correo-avisos.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Correo y Avisos Institucionales</a>
        <a href="editor.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('editor.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Configuración del Sistema</a>
        <a href="security.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('security.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Seguridad y Accesos</a>
        <?php if (function_exists('admin_maintenance_tools_enabled') && admin_maintenance_tools_enabled()): ?>
        <a href="mail-test-calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('mail-test-calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Diagnóstico SMTP</a>
        <a href="sql.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('sql.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Mantenimiento Base de Datos</a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- 4. ENLACES EXTERNOS -->
        <div class="sidebar-section-title">Portales Externos</div>
        <a href="/" class="nav-link" target="_blank" rel="noopener">Sitio Web Oficial ↗</a>
        <a href="/wp-admin/" class="nav-link" target="_blank" rel="noopener">Administración WordPress ↗</a>

        <!-- 5. ACCIONES & CIERRE -->
        <hr class="sidebar-divider" style="margin:14px 0 8px 0;border:0;border-top:1px solid rgba(255,255,255,0.1);">
        <?php if ($ccg_can_manage_site): ?>
        <form class="nav-form" method="POST" action="rebuild.php" style="margin-bottom:6px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($ccg_csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="nav-link btn btn-success" style="border-radius:2px;padding:8px 12px;font-weight:700;color:#fff;width:100%;text-align:center;justify-content:center;cursor:pointer;">Publicar cambios web</button>
        </form>
        <?php endif; ?>
        <a href="index.php?logout=1" class="nav-link nav-link--muted" style="color:#ffc9c9;">Cerrar sesión</a>
    </div>
</div>
