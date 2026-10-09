<?php
declare(strict_types=1);

/**
 * The reviewer page (Section 24).
 *
 * Opened from a link, with no account and no password. The link grants
 * exactly one thing: the ability to approve or reject the specific items it
 * was created for. It cannot read leads, conversations or anything else, and
 * it stops working when it expires or is revoked.
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

Security::htmlHeaders();
Security::startSession('aisa_review');

$raw   = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$token = ReviewWorkflow::resolveToken($raw);

function review_page(string $title, string $body, string $eyebrow = 'Content review'): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($title) . '</title>'
       . '<link rel="stylesheet" href="' . e(url('assets/admin.css')) . '">'
       . '</head><body class="review-page"><div class="review-shell">'
       . '<header class="review-head"><p class="review-eyebrow">' . e($eyebrow) . '</p>'
       . '<h1>' . e($title) . '</h1></header>' . $body
       . '</div></body></html>';
}

if ($token === null) {
    http_response_code(404);
    review_page(
        'This link is no longer valid',
        '<div class="card"><p>It may have expired, already been finished, or been withdrawn.</p>'
        . '<p class="muted">Ask whoever sent it to issue a new one.</p></div>'
    );
    exit;
}

$agent = AgentResolver::byIdForAdmin((int)$token['agent_id']);
if ($agent === null) {
    http_response_code(404);
    review_page('This link is no longer valid', '<div class="card"><p>The event it belongs to is no longer available.</p></div>');
    exit;
}

// ---------------------------------------------------------------------
// A decision
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'finish') {
        ReviewWorkflow::closeToken($token);
        review_page(
            'Thank you',
            '<div class="card"><p>Your decisions have been recorded and the team has been notified.</p>'
            . '<p class="muted">This link is now closed.</p></div>'
        );
        exit;
    }

    $type     = (string)($_POST['type'] ?? '');
    $itemId   = (int)($_POST['item_id'] ?? 0);
    $decision = Validator::inList($_POST['decision'] ?? null, ['approved', 'rejected'], null);
    $comment  = Validator::paragraph($_POST['comment'] ?? null, 2000);

    if ($decision === 'rejected' && ($comment === null || trim($comment) === '')) {
        $_SESSION['review_error'] = 'Please say briefly what is wrong, so the team knows what to change.';
    } elseif ($decision !== null) {
        ReviewWorkflow::applyDecision($token, $type, $itemId, $decision, $comment, Security::clientIp());
    }

    header('Location: ' . url('review/?t=' . rawurlencode($raw)));
    exit;
}

// ---------------------------------------------------------------------
// The list
// ---------------------------------------------------------------------
$items    = ReviewWorkflow::itemsForToken($token);
$decided  = array_filter($items, static fn($i) => $i['decided']);
$outstanding = count($items) - count($decided);
$error    = $_SESSION['review_error'] ?? null;
unset($_SESSION['review_error']);

$csrf = Security::csrfField();
$body = '';

if ($error !== null) {
    $body .= '<div class="alert alert-error"><p>' . e((string)$error) . '</p></div>';
}

$body .= '<div class="card"><p>You have been asked to check the following before it is used by the '
       . e((string)$agent['name']) . ' assistant. Nothing here is visible to visitors yet.</p>'
       . '<p class="muted">' . (int)count($decided) . ' of ' . (int)count($items) . ' done.</p></div>';

foreach ($items as $entry) {
    $type = (string)$entry['type'];
    $item = $entry['item'];
    $id   = (int)$item['id'];

    $class = 'card review-item';
    if ($entry['decided']) {
        $class .= $entry['decision'] === 'approved' ? ' is-approved' : ' is-rejected';
    }

    $body .= '<div class="' . $class . '">';
    $body .= '<div class="card-head"><h2>' . e(ReviewWorkflow::titleOf($type, $item)) . '</h2>'
           . '<span class="pill">' . e((string)$entry['label']) . '</span></div>';

    // What is being proposed, and what it would replace.
    if ($type === 'knowledge_article') {
        $old = (string)($item['approved_version_body'] ?? '');
        if ($old !== '') {
            $body .= '<p class="muted small">Currently live:</p><div class="review-body diff-old">' . e($old) . '</div>'
                   . '<p class="muted small">Proposed:</p>';
        }
        $body .= '<div class="review-body' . ($old !== '' ? ' diff-new' : '') . '">' . e((string)$item['body']) . '</div>';
    } elseif ($type === 'faq') {
        $old = (string)($item['approved_version_answer'] ?? '');
        if ($old !== '') {
            $body .= '<p class="muted small">Currently live:</p><div class="review-body diff-old">' . e($old) . '</div>'
                   . '<p class="muted small">Proposed:</p>';
        }
        $body .= '<div class="review-body' . ($old !== '' ? ' diff-new' : '') . '">' . e((string)$item['answer']) . '</div>';
    } else {
        $isStall = $type === 'stall_package';
        $card    = PricingEngine::toCard(
            $item,
            $isStall ? PricingEngine::TYPE_STALL : PricingEngine::TYPE_SPONSORSHIP
        );

        $snapshot = json_decode((string)($item['approved_snapshot'] ?? ''), true);
        if (is_array($snapshot) && $snapshot) {
            $oldRow  = array_merge($item, $snapshot);
            $oldCard = PricingEngine::toCard($oldRow, $isStall ? PricingEngine::TYPE_STALL : PricingEngine::TYPE_SPONSORSHIP);
            $body .= '<p class="muted small">Currently live:</p><div class="review-body diff-old">';
            foreach ($oldCard['price_lines'] as $line) {
                $body .= e((string)$line['label']) . ': ' . e((string)$line['value']) . "\n";
            }
            $body .= '</div><p class="muted small">Proposed:</p>';
        }

        $body .= '<div class="review-body' . (is_array($snapshot) && $snapshot ? ' diff-new' : '') . '">';
        foreach ($card['price_lines'] as $line) {
            $body .= e((string)$line['label']) . ': ' . e((string)$line['value']) . "\n";
        }
        if ($card['meta']) {
            $body .= "\n" . e(implode(' / ', $card['meta'])) . "\n";
        }
        if ($card['items']) {
            $body .= "\nIncludes:\n";
            foreach ($card['items'] as $inc) {
                $body .= '  - ' . e((string)$inc) . "\n";
            }
        }
        $body .= "\n" . e((string)$card['tax_note']);
        $body .= '</div>';
    }

    if ($entry['decided']) {
        $body .= '<p><span class="pill ' . ($entry['decision'] === 'approved' ? 'pill-ok' : 'pill-bad') . '">'
               . ($entry['decision'] === 'approved' ? 'You approved this' : 'You sent this back') . '</span></p>';
        if (trim((string)$entry['comment']) !== '') {
            $body .= '<p class="muted small">Your comment: ' . e((string)$entry['comment']) . '</p>';
        }
    } else {
        $body .= '<form method="post">' . $csrf
               . '<input type="hidden" name="t" value="' . e($raw) . '">'
               . '<input type="hidden" name="type" value="' . e($type) . '">'
               . '<input type="hidden" name="item_id" value="' . $id . '">'
               . '<div class="field"><label for="c' . $id . '">Comment</label>'
               . '<textarea id="c' . $id . '" name="comment" rows="2" '
               . 'placeholder="Optional when approving. Required if you send it back."></textarea></div>'
               . '<div class="btn-row">'
               . '<button class="btn btn-ok" type="submit" name="decision" value="approved">Approve</button>'
               . '<button class="btn btn-quiet" type="submit" name="decision" value="rejected">Send back for changes</button>'
               . '</div></form>';
    }

    $body .= '</div>';
}

if ($outstanding === 0 && $items) {
    $body .= '<form method="post" class="card">' . $csrf
           . '<input type="hidden" name="t" value="' . e($raw) . '">'
           . '<input type="hidden" name="action" value="finish">'
           . '<h2>All done</h2>'
           . '<p class="lede">Close this review when you are finished. The link will stop working.</p>'
           . '<button class="btn" type="submit">Finish and close</button></form>';
}

review_page(
    'Please review this content',
    $body,
    (string)$agent['name']
);
