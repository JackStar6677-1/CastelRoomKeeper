<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_bootstrap_session();

$catalogPath = __DIR__ . '/../data/documents.json';
$id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['id'] ?? '')));
$documents = json_decode((string) @file_get_contents($catalogPath), true);
$document = null;

foreach (is_array($documents) ? $documents : [] as $candidate) {
    if (($candidate['id'] ?? '') === $id) {
        $document = $candidate;
        break;
    }
}

if ($document === null) {
    http_response_code(404);
    exit('Documento no encontrado en el catálogo.');
}

// Si el documento es privado/admin, requiere autenticación activa
if (($document['visibility'] ?? 'public') !== 'public') {
    admin_require_login();
}

$relativePath = (string) ($document['path'] ?? '');
if (!preg_match('#^/wp-content/uploads/[A-Za-z0-9%._/() -]+$#', $relativePath)) {
    http_response_code(400);
    exit('Ruta inválida.');
}

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__))) ?: dirname(__DIR__);
$uploadsRoot = realpath($documentRoot . '/wp-content/uploads');
$resolved = realpath($documentRoot . rawurldecode($relativePath));

// Compatibilidad total PHP 7.4 / 8.x sin depender de str_starts_with
$is_inside = ($uploadsRoot !== false && $resolved !== false && strncmp($resolved, $uploadsRoot, strlen($uploadsRoot)) === 0);

if (!$is_inside || !is_file($resolved)) {
    http_response_code(404);
    exit('Archivo físico no disponible en el servidor.');
}

$extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
$types = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'csv' => 'text/csv; charset=utf-8',
    'zip' => 'application/zip',
];

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($resolved));
header('Content-Disposition: inline; filename="' . addcslashes(basename($resolved), '"\\') . '"');
readfile($resolved);
