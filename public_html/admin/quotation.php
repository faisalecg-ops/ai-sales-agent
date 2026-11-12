<?php
declare(strict_types=1);

/**
 * Opens one quotation's PDF. The file lives outside the web folder, so this
 * page is the only way to read it, and only for staff who may see the
 * exhibition it belongs to.
 */

require __DIR__ . '/_admin.php';

admin_require_access('view');
$agent = admin_agent();

$row = Quotation::ready()
    ? db()->one('SELECT * FROM quotations WHERE id = ? AND agent_id = ?', [(int)($_GET['id'] ?? 0), (int)$agent['id']])
    : null;
$file = $row !== null ? Quotation::path($row) : null;

if ($file === null) {
    http_response_code(404);
    admin_layout_start('Quotation not found');
    echo '<div class="alert alert-error"><p>That quotation does not exist for this exhibition, or its file is missing.</p></div>';
    admin_layout_end();
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$row['number']) . '.pdf"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
