<?php
declare(strict_types=1);

/**
 * "Connect" for automatic posting. A POST here sends the admin to Meta,
 * LinkedIn or X to sign in and press "Allow"; the platform then sends them
 * back here (this address is the redirect / callback URL of our apps).
 */
require __DIR__ . '/_social.php';

$user = sm_page('admin');
$aid = sm_agent_id();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $app = sm_in('app');
    if (!isset(SmApps::LABELS[$app])) {
        sm_redirect('connections.php');
    }
    $url = SmConnect::startUrl($app, $aid);
    if ($url === null) {
        sm_flash('The ' . SmApps::LABELS[$app] . ' app is not set up yet. A super admin enters its keys under "Platform apps" on this page.', 'error');
        sm_redirect('connections.php');
    }
    // A page with the link rather than a redirect: the admin pages only allow forms to post to this site.
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta http-equiv="refresh" content="0;url=' . e($url) . '"><title>Connecting…</title></head>'
       . '<body style="font:16px/1.5 system-ui,sans-serif;padding:2rem"><p>Taking you to ' . e(SmApps::LABELS[$app]) . ' to sign in and press "Allow"…</p>'
       . '<p><a href="' . e($url) . '">Continue</a></p></body></html>';
    exit;
}

// Back from the platform.
$state = sm_in('state');
$flow = $state !== '' ? SmConnect::takeFlow($state) : null;
if ($flow === null) {
    sm_flash('That connection attempt has expired or did not start here. Press Connect again.', 'error');
    sm_redirect('connections.php');
}
if ((int)$flow['agent'] !== $aid) {
    sm_flash('Nothing was connected: you switched to another exhibition while connecting. Switch back and press Connect again.', 'error');
    sm_redirect('connections.php');
}
if (sm_in('error') !== '' || sm_in('code') === '') {
    sm_flash('The connection was not made' . (sm_in('error_description') !== '' ? ': ' . mb_substr(sm_in('error_description'), 0, 200) : ' (it was cancelled on the platform\'s page).'), 'warn');
    sm_redirect('connections.php');
}
$res = SmConnect::finish($flow, sm_in('code'), (int)$user['id']);
if (!$res['ok']) {
    SmActivity::log($aid, 'connection_failed', SmApps::LABELS[$flow['app']]);
    sm_flash((string)$res['error'], 'error');
    sm_redirect('connections.php');
}
if (!empty($res['connected'])) {
    SmActivity::log($aid, 'connected', SmEvent::PLATFORMS[$res['connected']]);
    sm_flash(SmEvent::PLATFORMS[$res['connected']] . ' is connected. Switch it to "automatic" below when you want posts to go out by themselves.');
    sm_redirect('connections.php');
}
// Several pages: the admin chooses the event's own (tokens kept encrypted meanwhile).
$choices = array_map(static function (array $c): array {
    $c['token'] = Crypto::encrypt((string)$c['token']);
    if (!empty($c['refresh_token'])) {
        $c['refresh_token'] = Crypto::encrypt((string)$c['refresh_token']);
    }
    return $c;
}, (array)$res['choices']);
$_SESSION['sm_choose'] = ['app' => $flow['app'], 'agent' => $aid, 'at' => time(), 'choices' => $choices];
sm_redirect('connections.php', ['choose' => 1]);
