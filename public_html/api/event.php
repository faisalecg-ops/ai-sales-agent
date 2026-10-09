<?php
declare(strict_types=1);

/**
 * GET /api/event.php?agent=buildasia
 *
 * The exhibition's public facts — dates, venue, website and booking pages —
 * for the event's own blog (countdown, buttons, Google's event listing).
 * Read-only, and only what the exhibition already publishes: nothing about
 * prices, leads or settings. The blog fetches this once a day.
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

$send = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $send(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
$slug = Validator::slug((string)($_GET['agent'] ?? ''));
if ($slug === null) {
    $send(['ok' => false, 'error' => 'agent_required'], 400);
}
$agent = db()->one("SELECT * FROM agents WHERE slug = ? AND status = 'active'", [$slug]);
if (!$agent) {
    $send(['ok' => false, 'error' => 'unknown_agent'], 404);
}

$field = static fn(string $k): string => trim((string)($agent[$k] ?? ''));
header('Cache-Control: public, max-age=3600');
$send(['ok' => true, 'event' => [
    'name'           => $field('name'),
    'tagline'        => $field('event_tagline'),
    'start_date'     => $field('event_starts_on'),
    'end_date'       => $field('event_ends_on'),
    'venue'          => $field('event_venue'),
    'city'           => $field('event_city'),
    'country'        => $field('event_country'),
    'website_url'    => $field('website_url'),
    'register_url'   => $field('register_url'),
    'book_stand_url' => $field('book_stand_url'),
]]);
