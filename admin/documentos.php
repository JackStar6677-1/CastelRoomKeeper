<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_bootstrap_session();
admin_require_site_admin();

$catalogPath = __DIR__ . '/../data/documents.json';
$message = '';
$message_type = 'success';
$documents = [];

try {
    if (file_exists($catalogPath)) {
        $content = (string) file_get_contents($catalogPath);
        $documents = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
    if (!is_array($documents)) {
        $documents = [];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!admin_validate_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('La sesión expiró. Recarga la página e inténtalo otra vez.');
        }

        $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['id'] ?? '')));
        $visibility = ($_POST['visibility'] ?? '') === 'public' ? 'public' : 'admin';
        $updated = false;

        foreach ($documents as &$document) {
            if (($document['id'] ?? '') === $id) {
                $document['visibility'] = $visibility;
                $updated = true;
                break;
            }
        }
        unset($document);

        if (!$updated) {
            throw new RuntimeException('Documento no encontrado en el catálogo.');
        }

        $json = json_encode($documents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($catalogPath, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el catálogo en el servidor.');
        }

        admin_log_operation('documents_catalog', 'update_visibility', 'ok', [
            'id' => $id,
            'visibility' => $visibility,
            'user' => $_SESSION['admin_email'] ?? 'admin'
        ], "Actualizada visibilidad de documento {$id} a {$visibility}");

        $message = 'Visibilidad del documento actualizada correctamente.';
        $message_type = 'success';
    }
} catch (Throwable $error) {
    $message = 'Error: ' . $error->getMessage();
    $message_type = 'error';
}

$current_user = admin_current_user();
$current_role = admin_user_role($current_user);
$can_manage_users = in_array($current_role, ['admin', 'directivo'], true);
$csrf = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Gestión de Documentos Oficiales | CCG Admin</title>
    <meta name="theme-color" content="#0f264f">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f264f;
            --navy-soft: #1d3b70;
            --forest: #1b8252;
            --sky: #7bc4ff;
            --gold: #d6aa43;
            --ink: #18304b;
            --bg: #eef2f6;
            --card-bg: rgba(255, 255, 255, 0.96);
            --line: rgba(15, 38, 79, 0.12);
            --radius-lg: 2px;
            --radius-md: 2px;
            --shadow: 0 14px 34px rgba(15, 38, 79, 0.09);
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(180deg, #e9eff5 0%, #dbe5ee 100%);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Top Bar */
        .top-bar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(15, 38, 79, 0.94);
            border-bottom: 1px solid rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            padding: 10px 16px;
            color: #ffffff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .top-bar__inner {
            max-width: 1140px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-bar__logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #ffffff;
        }

        .top-bar__logo img {
            height: 38px;
            width: auto;
            background: #ffffff;
            padding: 2px 5px;
            border-radius: 2px;
        }

        .top-bar__title {
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .top-bar__sub {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--sky);
            font-weight: 700;
        }

        .top-bar__nav {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .nav-pill {
            text-decoration: none;
            padding: 7px 13px;
            border-radius: 2px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            transition: all 0.18s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-height: 36px;
        }

        .nav-pill:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .nav-pill--active {
            background: var(--forest);
            border-color: var(--forest);
        }

        .nav-pill--hub {
            background: rgba(123, 196, 255, 0.25);
            border-color: rgba(123, 196, 255, 0.4);
            color: #ffffff;
        }

        main {
            flex: 1;
            max-width: 1140px;
            width: calc(100% - 32px);
            margin: 22px auto 48px;
        }

        .page-header {
            margin-bottom: 20px;
        }

        .page-header h1 {
            margin: 0 0 6px;
            font-size: clamp(1.6rem, 3.5vw, 2.2rem);
            font-weight: 800;
            color: var(--navy);
            letter-spacing: -0.02em;
        }

        .page-header p {
            margin: 0;
            color: #475569;
            font-size: 0.98rem;
            line-height: 1.5;
        }

        /* Notice Alerts */
        .notice {
            padding: 13px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 18px;
            font-size: 0.92rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notice--success {
            background: #dcfce7;
            color: #14532d;
            border: 1px solid #86efac;
        }

        .notice--error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* Controls: Search and Filters */
        .controls-panel {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--line);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 240px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 11px 16px 11px 16px;
            border-radius: 2px;
            border: 1px solid #cbd5e1;
            font: inherit;
            font-size: 16px; /* Evita zoom iOS */
            background: #f8fafc;
            color: var(--ink);
            outline: none;
            transition: border-color 0.2s;
        }

        .search-box input:focus {
            border-color: #3b82f6;
            background: #ffffff;
        }

        .search-icon {
            display: none;
        }

        .doc-count {
            font-size: 0.86rem;
            font-weight: 700;
            color: #64748b;
        }

        /* Category Filter Chips */
        .category-chips {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 4px;
            margin-bottom: 18px;
            -webkit-overflow-scrolling: touch;
        }

        .chip {
            padding: 6px 14px;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid #cbd5e1;
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s ease;
            min-height: 34px;
            display: inline-flex;
            align-items: center;
        }

        .chip:hover, .chip.is-active {
            background: var(--navy);
            color: #ffffff;
            border-color: var(--navy);
        }

        /* Document Grid / Cards */
        .docs-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }

        .doc-card {
            background: var(--card-bg);
            border: 1px solid rgba(15, 38, 79, 0.1);
            border-radius: 2px;
            padding: 20px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .doc-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 36px rgba(15, 38, 79, 0.13);
        }

        .doc-card__top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .doc-category-badge {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 4px 10px;
            border-radius: 2px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .doc-status-badge {
            font-size: 0.72rem;
            font-weight: 800;
            padding: 4px 9px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-transform: uppercase;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .doc-status-badge--public {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .doc-status-badge--admin {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .doc-title {
            margin: 0 0 8px;
            font-size: 1.08rem;
            font-weight: 800;
            color: var(--navy);
            line-height: 1.35;
        }

        .doc-path {
            font-size: 0.76rem;
            color: #64748b;
            word-break: break-all;
            margin-bottom: 16px;
            display: block;
            font-family: monospace;
            background: #f8fafc;
            padding: 5px 8px;
            border-radius: 2px;
            border: 1px solid #e2e8f0;
        }

        .doc-actions {
            border-top: 1px solid #f1f5f9;
            padding-top: 14px;
            margin-top: auto;
        }

        .doc-form {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 8px;
            align-items: center;
        }

        .doc-form select {
            padding: 10px 12px;
            border-radius: 2px;
            border: 1px solid #cbd5e1;
            font: inherit;
            font-size: 0.88rem;
            background: #ffffff;
            color: var(--ink);
            min-height: 44px;
        }

        .btn-save {
            padding: 10px 14px;
            border-radius: 2px;
            border: 0;
            background: var(--forest);
            color: #ffffff;
            font: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            min-height: 44px;
            transition: background 0.18s;
        }

        .btn-save:hover {
            filter: brightness(1.08);
        }

        .btn-open {
            padding: 10px 14px;
            border-radius: 2px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #1e3a52;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            transition: all 0.18s;
        }

        .btn-open:hover {
            background: #f1f5f9;
            color: #0f264f;
            border-color: #94a3b8;
        }

        @media (max-width: 640px) {
            .top-bar {
                padding: 10px 12px;
            }
            .top-bar__inner {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .top-bar__nav {
                overflow-x: auto;
                flex-wrap: nowrap;
                padding-bottom: 2px;
                -webkit-overflow-scrolling: touch;
            }
            main {
                width: calc(100% - 20px);
                margin: 16px auto 36px;
            }
            .docs-list {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .doc-card {
                padding: 16px 14px;
            }
            .doc-form {
                grid-template-columns: 1fr;
            }
            .btn-save, .btn-open {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header class="top-bar">
        <div class="top-bar__inner">
            <a href="hub.php" class="top-bar__logo" title="Volver al Hub Digital">
                <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="CCG">
                <div>
                    <div class="top-bar__title">Documentos Web</div>
                    <div class="top-bar__sub">Colegio Castelgandolfo · CCG Admin</div>
                </div>
            </a>
            <nav class="top-bar__nav">
                <a href="hub.php" class="nav-pill nav-pill--hub">Ecosistema Hub</a>
                <a href="calendar.php" class="nav-pill">Calendario</a>
                <a href="logs.php" class="nav-pill">Logs</a>
                <?php if ($can_manage_users): ?>
                <a href="usuarios.php" class="nav-pill">Usuarios</a>
                <?php endif; ?>
                <a href="documentos.php" class="nav-pill nav-pill--active">Documentos</a>
                <a href="/" target="_blank" rel="noopener" class="nav-pill">Sitio web ↗</a>
                <a href="index.php?logout=1" class="nav-pill" style="color:#ffc9c9;border-color:rgba(255,150,150,0.3);">Salir</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="page-header">
            <h1>Catálogo de Documentos Oficiales</h1>
            <p>Controla la visibilidad y acceso de reglamentos, circulares, listas de útiles escolares y planes lectores publicados en el sitio web institucional.</p>
        </div>

        <?php if ($message !== ''): ?>
        <div class="notice <?= $message_type === 'success' ? 'notice--success' : 'notice--error' ?>">
            <?= $message_type === 'success' ? 'OK · ' : 'AVISO · ' ?> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="controls-panel">
            <div class="search-box">
                <input type="search" id="docSearch" placeholder="Buscar por título, nivel, año o nombre de archivo…" onkeyup="filterDocs()" aria-label="Buscar documentos">
            </div>
            <div class="doc-count" id="docCount">Mostrando <?= count($documents) ?> documentos</div>
        </div>

        <div class="category-chips">
            <button type="button" class="chip is-active" onclick="filterCategory('all', this)">Todos (<?= count($documents) ?>)</button>
            <button type="button" class="chip" onclick="filterCategory('Institucional', this)">Institucional</button>
            <button type="button" class="chip" onclick="filterCategory('Convivencia y Evaluación', this)">Convivencia & PISE</button>
            <button type="button" class="chip" onclick="filterCategory('Becas y Admisión', this)">Becas & Admisión</button>
            <button type="button" class="chip" onclick="filterCategory('Listas de Útiles 2026', this)">Útiles 2026</button>
            <button type="button" class="chip" onclick="filterCategory('Plan Lector 2026', this)">Plan Lector 2026</button>
        </div>

        <div class="docs-list" id="docsList">
            <?php foreach ($documents as $doc): 
                $is_public = ($doc['visibility'] ?? 'public') === 'public';
                $cat = $doc['category'] ?? 'General';
            ?>
            <article class="doc-card" data-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" data-visibility="<?= $is_public ? 'public' : 'admin' ?>">
                <div>
                    <div class="doc-card__top">
                        <span class="doc-category-badge"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="doc-status-badge <?= $is_public ? 'doc-status-badge--public' : 'doc-status-badge--admin' ?>">
                            <?= $is_public ? 'Público' : 'Solo Admin' ?>
                        </span>
                    </div>
                    <h3 class="doc-title"><?= htmlspecialchars((string) ($doc['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                    <code class="doc-path"><?= htmlspecialchars((string) ($doc['path'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                </div>

                <div class="doc-actions">
                    <form method="POST" class="doc-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($doc['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <select name="visibility" aria-label="Visibilidad">
                            <option value="public" <?= $is_public ? 'selected' : '' ?>>Público</option>
                            <option value="admin" <?= !$is_public ? 'selected' : '' ?>>Solo Administración</option>
                        </select>
                        <button type="submit" class="btn-save">Guardar</button>
                        <a href="documento.php?id=<?= rawurlencode((string) ($doc['id'] ?? '')) ?>" target="_blank" rel="noopener" class="btn-open" title="Abrir y verificar documento">
                            Abrir ↗
                        </a>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        let currentCategory = 'all';

        function filterCategory(cat, el) {
            currentCategory = cat;
            document.querySelectorAll('.chip').forEach(c => c.classList.remove('is-active'));
            if (el) el.classList.add('is-active');
            filterDocs();
        }

        function filterDocs() {
            const query = (document.getElementById('docSearch').value || '').toLowerCase().trim();
            const cards = document.querySelectorAll('.doc-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                const cardCat = card.getAttribute('data-category');
                
                const matchesCat = (currentCategory === 'all' || cardCat === currentCategory);
                const matchesQuery = (!query || text.includes(query));

                if (matchesCat && matchesQuery) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            document.getElementById('docCount').textContent = `Mostrando ${visibleCount} de ${cards.length} documentos`;
        }
    </script>
</body>
</html>
