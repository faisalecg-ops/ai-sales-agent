<?php
/** API keys for the Social Media Agent, and the "article published" notice it receives. */
require_perm('api');

if (is_post()) {
    post_guard();
    $do = (string)in('do');
    if ($do === 'create') {
        $res = ApiKey::create(in_str('name', 120), (string)in('permission'));
        $_SESSION['new_api_key'] = $res['key'];
        back_to('apikeys');
    }
    if ($do === 'toggle') {
        db()->exec('UPDATE {p}api_keys SET active = 1 - active WHERE id = ?', [(int)in('id')]);
        Activity::log('api_key.toggled', (string)in('id'));
        flash('ok', 'Key updated.');
    }
    if ($do === 'delete') {
        db()->delete('api_keys', 'id = ?', [(int)in('id')]);
        Activity::log('api_key.deleted', (string)in('id'));
        flash('ok', 'Key deleted. Anything using it stops working at once.');
    }
    if ($do === 'webhook') {
        $u = in_str('webhook_url', 500);
        if ($u !== '' && (!is_web_url($u) || !str_starts_with($u, 'https://'))) {
            flash('err', 'The notice address must start with https://');
            back_to('apikeys');
        }
        Settings::set('webhook_url', $u);
        if (in('new_secret')) {
            Settings::set('webhook_secret', bin2hex(random_bytes(24)));
        }
        Activity::log('webhook.updated');
        flash('ok', 'Saved.');
    }
    if ($do === 'retry') {
        db()->exec("UPDATE {p}webhook_outbox SET status = 'queued', attempts = 0, next_try_at = ? WHERE status = 'failed'", [now_utc()]);
        $n = Webhook::flush(20);
        flash('ok', $n . ' notice(s) sent.');
    }
    back_to('apikeys');
}

$newKey = $_SESSION['new_api_key'] ?? null;
unset($_SESSION['new_api_key']);
$keys = db()->all('SELECT k.*, u.name AS creator FROM {p}api_keys k LEFT JOIN {p}users u ON u.id = k.created_by ORDER BY k.active DESC, k.id DESC');
$outbox = db()->all('SELECT w.*, a.title FROM {p}webhook_outbox w LEFT JOIN {p}articles a ON a.id = w.article_id ORDER BY w.id DESC LIMIT 10');

ob_start(); ?>
<?php if ($newKey): ?>
<div class="flash flash--warn newkey"><strong>Copy this key now — it will not be shown again.</strong>
  <code class="keybox" data-copytext><?= e($newKey) ?></code>
  <button type="button" class="btn btn--small" data-copybtn>Copy</button>
  <p class="small">Paste it into the Social Media Agent's settings for this exhibition.</p></div>
<?php endif; ?>

<section class="card">
  <h2>API keys</h2>
  <p class="help">The Social Media Agent uses a key to send articles into this blog. Keys are stored scrambled, work only over secure connections, and are limited to a sensible number of requests per minute.</p>
  <?php if ($keys): ?>
  <table class="table"><thead><tr><th>Name</th><th>Permission</th><th class="hide-s">Key starts</th><th class="hide-s">Last used</th><th></th></tr></thead><tbody>
  <?php foreach ($keys as $k): ?>
    <tr<?= $k['active'] ? '' : ' class="is-off"' ?>><td><strong><?= e($k['name']) ?></strong><?= $k['active'] ? '' : ' <span class="tag">Off</span>' ?><br><span class="small muted">Created <?= e(local_date($k['created_at'], 'j M Y')) ?><?= $k['creator'] ? ' by ' . e($k['creator']) : '' ?></span></td>
      <td><?= $k['permission'] === 'publish' ? '<span class="badge badge--published">Can publish</span>' : '<span class="badge badge--pending">Drafts only</span>' ?></td>
      <td class="hide-s"><code><?= e($k['key_prefix']) ?>…</code></td>
      <td class="hide-s small"><?= $k['last_used_at'] ? e(local_date($k['last_used_at'], 'j M, H:i')) : 'Never' ?></td>
      <td class="right nowrap"><form method="post" class="inline"><?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
        <button class="btn btn--small btn--ghost" name="do" value="toggle"><?= $k['active'] ? 'Switch off' : 'Switch on' ?></button>
        <button class="btn btn--small btn--quiet" name="do" value="delete" onclick="return confirm('Delete this key? Anything using it stops working.')">Delete</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <form method="post" class="grid3">
    <?= Auth::csrfField() ?><input type="hidden" name="do" value="create">
    <?= f_text('name', 'Name for the new key', 'Social Media Agent', '', ['maxlength' => 120, 'required' => true]) ?>
    <?= f_select('permission', 'Permission', ['drafts' => 'Drafts only — articles arrive awaiting approval (recommended)', 'publish' => 'Can publish — articles may go live without a person'], 'drafts') ?>
    <div class="field field--btn"><button class="btn btn--primary">Create key</button></div>
  </form>
  <p class="help">Even with “Can publish”, an article is held for approval if it contains a link that is not in the link library, not another article of this blog, and not a source the agent declared.</p>
</section>

<section class="card">
  <h2>“Article published” notice</h2>
  <p class="help">When an article is published, the blog tells the Social Media Agent its address, so the agent can write the social posts. The notice is signed with the secret below so the agent can trust it.</p>
  <form method="post">
    <?= Auth::csrfField() ?><input type="hidden" name="do" value="webhook">
    <?= f_text('webhook_url', 'Notice address (from the Social Media Agent)', (string)Settings::get('webhook_url', ''), 'Leave empty until the Social Media Agent is set up.', ['type' => 'url', 'placeholder' => 'https://salesagent.ecgateway.net/social/…']) ?>
    <div class="field"><label>Signing secret</label><code class="keybox"><?= e((string)Settings::get('webhook_secret', '')) ?></code></div>
    <?= f_check('new_secret', 'Make a new secret (the agent must then be given the new one)', false) ?>
    <button class="btn">Save</button>
  </form>
  <?php if ($outbox): ?>
    <h3>Recent notices</h3>
    <table class="table"><tbody><?php foreach ($outbox as $w): ?>
      <tr><td class="small"><?= e(local_date($w['created_at'], 'j M, H:i')) ?></td><td class="small"><?= e($w['title'] ?? '') ?></td>
        <td><?= $w['status'] === 'sent' ? '<span class="badge badge--published">Sent</span>' : ($w['status'] === 'failed' ? '<span class="badge badge--draft">Failed</span>' : '<span class="badge badge--pending">Waiting</span>') ?></td>
        <td class="small muted"><?= e($w['last_error']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if (array_filter($outbox, static fn($w) => $w['status'] === 'failed')): ?><form method="post"><?= Auth::csrfField() ?><button class="btn btn--small" name="do" value="retry">Try failed notices again</button></form><?php endif; ?>
  <?php endif; ?>
</section>

<section class="card">
  <h2>For the developer of the Social Media Agent</h2>
  <p class="small">Base address: <code><?= e(url('api/v1')) ?></code> · Send the key as <code>Authorization: Bearer &lt;key&gt;</code> · JSON in, JSON out. Full details: <code>docs/blog-api.md</code> in the package.</p>
  <table class="table small"><tbody>
    <tr><td><code>GET /categories</code></td><td>Categories (id, name, slug)</td></tr>
    <tr><td><code>GET /links</code></td><td>The link library: the only outside addresses articles may use besides declared sources</td></tr>
    <tr><td><code>POST /media</code></td><td>Upload an image (file or base64); returns its id</td></tr>
    <tr><td><code>POST /articles</code></td><td>Create an article (arrives awaiting approval with a drafts-only key)</td></tr>
    <tr><td><code>PATCH /articles/{id}</code></td><td>Update an article that has not been published yet</td></tr>
    <tr><td><code>GET /articles/{id}</code></td><td>Status and, once published, the address</td></tr>
  </tbody></table>
</section>
<?php admin_render('API keys', (string)ob_get_clean(), 'apikeys');
