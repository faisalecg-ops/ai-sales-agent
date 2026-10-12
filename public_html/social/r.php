<?php
declare(strict_types=1);

/**
 * The registration link inside an exhibitor's kit: counts the visit, then
 * sends the reader to the event's visitor registration (with tags saying it
 * came from that exhibitor's post). Public; no sign-in.
 */
require_once __DIR__ . '/lib/boot.php';

header('X-Robots-Tag: noindex, nofollow');
$ex = SmExhibitors::click((string)($_GET['k'] ?? ''));
$agent = $ex ? db()->one("SELECT * FROM agents WHERE id = ? AND status <> 'deleted'", [(int)$ex['agent_id']]) : null;
$to = $agent ? trim((string)SmEvent::facts($agent)['register']) : '';
if (!$ex || !preg_match('#^https?://#i', $to)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This link is not active.';
    exit;
}
$channel = in_array($_GET['c'] ?? '', ['linkedin', 'facebook'], true) ? (string)$_GET['c'] : 'share';
$q = http_build_query(['utm_source' => $channel, 'utm_medium' => 'exhibitor-share', 'utm_campaign' => (string)$agent['slug'], 'utm_content' => 'exhibitor-' . (int)$ex['id']]);
header('Location: ' . $to . (str_contains($to, '?') ? '&' : '?') . $q, true, 302);
