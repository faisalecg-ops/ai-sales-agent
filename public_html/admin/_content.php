<?php
declare(strict_types=1);

/**
 * The four content pages (knowledge, FAQs, stand pricing, sponsorship) all
 * do the same job: list records, edit one, and move it through the review
 * workflow. That logic lives here once, and each page supplies the fields
 * its own record type needs.
 */

/**
 * @param string $type   one of ReviewWorkflow::TYPES
 * @param array  $config title, singular name, list columns and form fields
 */
function content_page(string $type, array $config): void
{
    $user    = admin_require_access('manage');
    $agent   = admin_agent();
    $agentId = (int)$agent['id'];
    $table   = ReviewWorkflow::table($type);

    if ($table === null) {
        throw new RuntimeException('unknown content type');
    }

    $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    $isNew  = isset($_GET['new']);

    // ---- Save -------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        Security::requireCsrf();
        $action = (string)($_POST['action'] ?? 'save');
        $id     = (int)($_POST['id'] ?? 0);

        if ($action === 'save') {
            $values = [];
            $errors = [];

            foreach ($config['fields'] as $name => $field) {
                $raw = $_POST[$name] ?? null;
                $val = match ($field['type']) {
                    'checkbox' => !empty($raw) ? 1 : 0,
                    'int'      => ($raw === '' || $raw === null) ? null : (int)$raw,
                    'decimal'  => ($raw === '' || $raw === null) ? null : (float)$raw,
                    'date'     => Validator::date($raw),
                    'select'   => Validator::inList($raw, array_keys($field['options']), $field['default'] ?? null),
                    'textarea' => Validator::paragraph($raw, (int)($field['max'] ?? 20000)),
                    default    => Validator::text($raw, (int)($field['max'] ?? 255)),
                };
                // An empty box falls back to the field's default (for example a
                // category of "general"), never to NULL in a column that needs a value.
                if (($val === null || $val === '') && array_key_exists('default', $field) && $field['type'] !== 'checkbox') {
                    $val = $field['default'];
                }
                if (!empty($field['required']) && ($val === null || $val === '')) {
                    $errors[] = $field['label'] . ' is required.';
                }
                $values[$name] = $val;
            }

            // An optional box left empty is stored as empty text in a column
            // that cannot hold "nothing", rather than failing the whole save.
            $required = db()->all(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = 'NO'",
                [ReviewWorkflow::table($type)]
            );
            $notNull = array_flip(array_map(static fn($r) => (string)$r['COLUMN_NAME'], $required));
            foreach ($config['fields'] as $name => $field) {
                if ($values[$name] === null && isset($notNull[$name]) && in_array($field['type'], ['text', 'textarea'], true)) {
                    $values[$name] = '';
                }
            }

            // Type-specific checks, such as the currency rule on pricing.
            if (isset($config['validate'])) {
                $errors = array_merge($errors, $config['validate']($values, $agent));
            }

            if ($errors) {
                foreach ($errors as $e) {
                    admin_flash($e, 'error');
                }
                admin_redirect('admin/' . $config['file'] . ($id > 0 ? '?edit=' . $id : '?new=1'));
            }

            if ($id > 0) {
                $before = ReviewWorkflow::fetchItem($type, $id, $agentId);
                if (!$before) {
                    admin_flash('That record no longer exists.', 'error');
                    admin_redirect('admin/' . $config['file']);
                }
                db()->update($table, $values, 'id = ? AND agent_id = ?', [$id, $agentId]);

                // An edit to a live record sends it back for review, while
                // the previously approved version keeps serving visitors.
                ReviewWorkflow::recordEdit($agent, $type, $id, $before, $values, (string)$user['username']);

                $after = ReviewWorkflow::fetchItem($type, $id, $agentId);
                admin_flash((string)$after['status'] === 'pending_review' && (string)$before['status'] === 'approved'
                    ? 'Saved. Because this was already live, it now needs reviewing again. Visitors keep seeing the previous version until then.'
                    : 'Saved.');
            } else {
                $values['agent_id']   = $agentId;
                $values['status']     = 'draft';
                $values['created_by'] = (int)$user['id'];
                $id = db()->insert($table, $values);
                Audit::log(
                    in_array($type, ['stall_package', 'sponsorship_package'], true) ? 'pricing_changed' : 'knowledge_changed',
                    $agentId, $type, $id, 'Created'
                );
                admin_flash('Created as a draft. Send it for review when you are ready.');
            }

            admin_redirect('admin/' . $config['file'] . '?edit=' . $id);
        }

        if ($action === 'submit') {
            ReviewWorkflow::submitForReview($agent, $type, $id, (string)$user['username']);
            admin_flash('Sent for review.');
            admin_redirect('admin/' . $config['file']);
        }

        if ($action === 'approve') {
            ReviewWorkflow::approve($agent, $type, $id, (string)$user['username'], 'Approved in the admin panel');
            admin_flash('Approved and live.');
            admin_redirect('admin/' . $config['file']);
        }

        if ($action === 'archive') {
            ReviewWorkflow::archive($agent, $type, $id, (string)$user['username']);
            admin_flash('Archived. It is no longer used by the assistant.');
            admin_redirect('admin/' . $config['file']);
        }
    }

    // ---- Edit form --------------------------------------------------
    if ($editId > 0 || $isNew) {
        $item = $editId > 0 ? ReviewWorkflow::fetchItem($type, $editId, $agentId) : null;
        if ($editId > 0 && !$item) {
            admin_layout_start('Not found');
            echo '<div class="alert alert-error"><p>That record does not exist.</p></div>';
            admin_layout_end();
            return;
        }

        $title = $item ? 'Edit ' . $config['singular'] : 'New ' . $config['singular'];
        admin_layout_start($title, ['Back to list' => url('admin/' . $config['file'])]);

        if ($item) {
            echo '<div class="btn-row" style="margin-bottom:16px">' . status_pill((string)$item['status']);
            if ((string)$item['status'] === 'pending_review' && !empty($item['approved_snapshot'])) {
                echo '<span class="muted small">Visitors are still seeing the previously approved version.</span>';
            }
            echo '</div>';

            if (trim((string)($item['reviewer_comment'] ?? '')) !== '') {
                echo '<div class="alert alert-warn"><p><strong>Reviewer comment:</strong> '
                   . e((string)$item['reviewer_comment']) . '</p></div>';
            }
        }

        echo '<form method="post" class="settings-form">' . Security::csrfField()
           . '<input type="hidden" name="action" value="save">'
           . '<input type="hidden" name="id" value="' . (int)($item['id'] ?? 0) . '">';

        // Each group of fields is its own card. Short boxes (numbers, dates,
        // choices, short text) sit side by side; long ones take the full width.
        $group  = false;
        $cardNo = 0;
        foreach ($config['fields'] as $name => $field) {
            $fieldGroup = $field['group'] ?? null;
            if ($fieldGroup !== $group) {
                if ($group !== false) { echo '</div>' . admin_card_close(); }
                $group = $fieldGroup;
                $cardNo++;
                echo admin_card_open('part-' . $cardNo, $group ?? ucfirst((string)$config['singular']), '')
                   . '<div class="form-grid">';
            }

            $value = $item[$name] ?? ($field['default'] ?? '');
            $opts  = ['hint' => $field['hint'] ?? '', 'required' => !empty($field['required'])];
            $wide  = in_array($field['type'], ['textarea', 'checkbox'], true)
                  || ($field['type'] === 'text' && (int)($field['max'] ?? 999) > 64)
                  || ($field['type'] === 'select'
                      && max(array_map(static fn($o) => mb_strlen((string)$o), array_values($field['options']) ?: [''])) > 26);

            echo '<div class="fg' . ($wide ? ' fg-wide' : '') . '">' . match ($field['type']) {
                'textarea' => f_area($name, $field['label'], (string)$value, $opts + ['rows' => (int)($field['rows'] ?? 5), 'tall' => !empty($field['tall'])]),
                'select'   => f_select($name, $field['label'], $field['options'], (string)$value, $opts),
                'checkbox' => f_check($name, $field['label'], (int)$value === 1, (string)($field['hint'] ?? '')),
                'int', 'decimal' => f_text($name, $field['label'], $value === null ? '' : (string)$value, $opts + ['type' => 'number', 'step' => $field['type'] === 'int' ? '1' : '0.01']),
                'date'     => f_text($name, $field['label'], (string)$value, $opts + ['type' => 'date']),
                default    => f_text($name, $field['label'], (string)$value, $opts),
            } . '</div>';
        }
        if ($group !== false) { echo '</div>' . admin_card_close(); }

        echo '<div class="save-bar"><span class="save-note">'
           . ($item && (string)$item['status'] === 'approved'
                ? 'Saving sends the change for review. Visitors keep seeing the live version until it is approved.'
                : 'Nothing reaches a visitor until it is approved.')
           . '</span><a class="btn btn-quiet" href="' . e(url('admin/' . $config['file'])) . '">Cancel</a>'
           . '<button class="btn" type="submit">Save</button></div>'
           . '</form>';

        if ($item) {
            echo '<div class="card"><h3>Move this through review</h3>'
               . '<p class="lede">Nothing reaches a visitor until it is approved.</p><div class="btn-row">';

            if (in_array((string)$item['status'], ['draft', 'archived'], true)) {
                echo '<form method="post" style="margin:0">' . Security::csrfField()
                   . '<input type="hidden" name="action" value="submit"><input type="hidden" name="id" value="' . (int)$item['id'] . '">'
                   . '<button class="btn btn-small" type="submit">Send for review</button></form>';
            }
            if ((string)$item['status'] === 'pending_review') {
                echo '<form method="post" style="margin:0">' . Security::csrfField()
                   . '<input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="' . (int)$item['id'] . '">'
                   . '<button class="btn btn-small btn-ok" type="submit">Approve and publish</button></form>';
            }
            if ((string)$item['status'] !== 'archived') {
                echo '<form method="post" style="margin:0" data-confirm="Archive this? The assistant will stop using it.">'
                   . Security::csrfField()
                   . '<input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="' . (int)$item['id'] . '">'
                   . '<button class="btn btn-small btn-quiet" type="submit">Archive</button></form>';
            }
            echo '</div></div>';

            $history = Audit::forEntity($type, (int)$item['id'], 25);
            if ($history) {
                echo '<div class="card"><h3>History</h3><table class="table"><thead><tr>'
                   . '<th>When</th><th>What</th><th>Field</th><th>From</th><th>To</th><th>By</th>'
                   . '</tr></thead><tbody>';
                foreach ($history as $h) {
                    echo '<tr><td class="small muted">' . e(admin_time((string)$h['created_at'])) . '</td>'
                       . '<td>' . e(PersonView::eventLabel((string)$h['event'])) . '</td>'
                       . '<td class="small">' . e(str_replace('_', ' ', (string)$h['field'])) . '</td>'
                       . '<td class="small">' . e(mb_substr((string)$h['old_value'], 0, 50)) . '</td>'
                       . '<td class="small">' . e(mb_substr((string)$h['new_value'], 0, 50)) . '</td>'
                       . '<td class="small muted">' . e(in_array((string)$h['actor'], ['system', ''], true) ? 'Platform' : (string)$h['actor']) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        }

        admin_layout_end();
        return;
    }

    // ---- List -------------------------------------------------------
    $filter = Validator::inList($_GET['status'] ?? null, ['draft', 'pending_review', 'approved', 'archived'], null);
    $where  = 'agent_id = ?';
    $params = [$agentId];
    if ($filter !== null) {
        $where .= ' AND status = ?';
        $params[] = $filter;
    }

    $items = db()->all(
        "SELECT * FROM {$table} WHERE {$where} ORDER BY " . $config['order'] . ' LIMIT 500',
        $params
    );

    admin_layout_start($config['title'], ['New ' . $config['singular'] => url('admin/' . $config['file'] . '?new=1')]);

    if (!empty($config['intro'])) {
        echo '<p class="lede">' . e($config['intro']) . '</p>';
    }

    echo '<div class="btn-row" style="margin-bottom:16px">';
    foreach ([null => 'All', 'approved' => 'Live', 'pending_review' => 'Awaiting review', 'draft' => 'Drafts', 'archived' => 'Archived'] as $key => $label) {
        $active = ((string)$filter === (string)$key) ? '' : ' btn-quiet';
        $href   = url('admin/' . $config['file'] . ($key === null ? '' : '?status=' . $key));
        echo '<a class="btn btn-small' . $active . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</div>';

    if (!$items) {
        echo '<div class="empty">Nothing here yet.</div>';
    } else {
        echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>';
        foreach ($config['columns'] as $col) {
            echo '<th' . (!empty($col['num']) ? ' class="num"' : '') . '>' . e($col['label']) . '</th>';
        }
        echo '<th>Status</th><th></th></tr></thead><tbody>';

        foreach ($items as $item) {
            echo '<tr>';
            foreach ($config['columns'] as $col) {
                $value = ($col['value'])($item);
                echo '<td' . (!empty($col['num']) ? ' class="num"' : '') . '>' . $value . '</td>';
            }
            echo '<td>' . content_status_pill($item) . '</td>'
               . '<td><a href="' . e(url('admin/' . $config['file'] . '?edit=' . (int)$item['id'])) . '">Edit</a></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    admin_layout_end();
}

/**
 * The status shown in a list. A live price whose dates have run out is not
 * live for visitors, so it says "Expired" (or "Not started yet") rather than
 * "Live" (fix plan step 4, 11 Nov 2026; testing finding 3).
 */
function content_status_pill(array $item): string
{
    if ((string)($item['status'] ?? '') === 'approved'
        && (array_key_exists('valid_until', $item) || array_key_exists('valid_from', $item))) {
        $today = gmdate('Y-m-d');
        $until = trim((string)($item['valid_until'] ?? ''));
        $from  = trim((string)($item['valid_from'] ?? ''));
        if ($until !== '' && $until < $today) {
            return '<span class="pill pill-bad" title="Ended on ' . e($until) . '. Visitors are not offered it.">Expired</span>';
        }
        if ($from !== '' && $from > $today) {
            return '<span class="pill pill-warn" title="Starts on ' . e($from) . '.">Not started yet</span>';
        }
    }
    return status_pill((string)($item['status'] ?? ''));
}
