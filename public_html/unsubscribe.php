<?php
declare(strict_types=1);

/**
 * Unsubscribing from prospect emails.
 *
 * The link in every prospect email opens this page. It is signed, so it can
 * only unsubscribe the address it was sent to. Opening it asks for one press
 * of a button — link-checkers in some mail systems open every link, and must
 * not unsubscribe people by doing so. Mail programs that offer their own
 * "Unsubscribe" button send a one-click request here (RFC 8058), which is
 * honoured straight away.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

Security::htmlHeaders();

function unsubscribe_page(string $title, string $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($title) . '</title>'
       . '<link rel="stylesheet" href="' . e(url('assets/admin.css')) . '">'
       . '</head><body class="review-page"><div class="review-shell">'
       . '<header class="review-head"><p class="review-eyebrow">Email preferences</p>'
       . '<h1>' . e($title) . '</h1></header>' . $body
       . '</div></body></html>';
}

$link = ProspectMailer::readUnsubscribe($_GET);

if ($link === null) {
    http_response_code(404);
    unsubscribe_page('This link is not valid',
        '<div class="card"><p>It may have been copied incompletely. Reply to the email with the word '
        . '“unsubscribe” and you will not be contacted again.</p></div>');
    exit;
}

$show = (string)$link['agent']['name'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    ProspectMailer::unsubscribe((int)$link['agent']['id'], $link['email']);
    unsubscribe_page('You are unsubscribed',
        '<div class="card"><p><strong>' . e($link['email']) . '</strong> will not receive any more emails about '
        . e($show) . ' from us.</p><p class="muted">If this was a mistake, reply to any of our emails and we will '
        . 'put it right.</p></div>');
    exit;
}

unsubscribe_page('Stop emails from ' . $show . '?',
    '<div class="card"><p>We will stop emailing <strong>' . e($link['email']) . '</strong> about ' . e($show) . '.</p>'
    . '<form method="post" action="' . e(url('unsubscribe.php') . '?' . http_build_query([
        'a' => (int)$link['agent']['id'], 'e' => (string)($_GET['e'] ?? ''), 't' => (string)($_GET['t'] ?? '')])) . '">'
    . '<button class="btn" type="submit">Unsubscribe</button></form></div>');
