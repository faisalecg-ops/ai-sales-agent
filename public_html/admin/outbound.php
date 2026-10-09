<?php
declare(strict_types=1);

/**
 * Stop or resume every outgoing message, for every exhibition.
 *
 * Only ever reached by a button, so it has no page of its own: it acts and
 * sends the person back where they were.
 */

require __DIR__ . '/_admin.php';

$user  = admin_require_role('admin');
$actor = (string)$user['username'];

// Only a page inside the admin panel may be returned to, so this cannot be
// used to send someone off to another site.
$back = (string)($_POST['return'] ?? 'admin/index.php');
if (preg_match('#^admin/[a-z_]+\.php(\?[a-z_]+=[A-Za-z0-9_\-]+)?$#', $back) !== 1) {
    $back = 'admin/index.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    admin_redirect($back);
}

Security::requireCsrf();
Audit::setActor($actor);

$action = (string)($_POST['action'] ?? '');

if ($action === 'stop') {
    OutboundSwitch::stop($actor, (string)($_POST['note'] ?? ''));
    admin_flash('All outgoing messages are stopped, for every exhibition. Nothing already waiting has been lost — '
        . 'it will go when you resume.', 'warn');
} elseif ($action === 'resume') {
    OutboundSwitch::resume($actor);
    admin_flash('Sending resumed. Waiting messages will go out within the next few minutes, within each '
        . 'exhibition\'s hours and limits. Replies more than 12 hours old are not sent.');
}

admin_redirect($back);
