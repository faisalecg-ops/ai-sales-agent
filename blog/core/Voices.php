<?php
declare(strict_types=1);

/**
 * Industry Voices: articles by outside experts (contributors), their public
 * profile pages, and the public "Write for us" form whose proposals wait for
 * an editor. Contributors need no login; the editorial team publishes for
 * them. A contributor may also be linked to an author account, which can
 * only send articles for approval.
 */
final class Voices
{
    public static function enabled(): bool
    {
        return (bool)Settings::get('voices_enabled', true);
    }

    public static function slug(): string
    {
        return slugify((string)Settings::get('voices_slug', 'industry-voices'), 60) ?: 'industry-voices';
    }

    public static function writeSlug(): string
    {
        return slugify((string)Settings::get('write_slug', 'write-for-us'), 60) ?: 'write-for-us';
    }

    public static function name(): string
    {
        return trim((string)Settings::get('voices_name', '')) ?: 'Industry Voices';
    }

    public static function badge(): string
    {
        return trim((string)Settings::get('voices_badge', '')) ?: 'Industry Voice';
    }

    public static function sectionUrl(): string
    {
        return url(self::slug() . '/');
    }

    public static function profileUrl(array $c): string
    {
        return url(self::slug() . '/' . $c['slug']);
    }

    public static function writeOpen(): bool
    {
        return self::enabled() && (bool)Settings::get('write_enabled', true);
    }

    /** "Views are the author's own…" with the event's name filled in. */
    public static function disclaimer(): string
    {
        return str_replace('{event}', Event::name(), (string)Settings::get('voices_disclaimer', ''));
    }

    /** Addresses the blog's own pages use: a category may not take them. */
    public static function reservedSlugs(): array
    {
        return [self::slug(), self::writeSlug()];
    }

    public static function find(int $id): ?array
    {
        return $id > 0 ? db()->one('SELECT * FROM {p}contributors WHERE id = ?', [$id]) : null;
    }

    public static function forUser(int $userId): ?array
    {
        return $userId > 0 ? db()->one('SELECT * FROM {p}contributors WHERE user_id = ? AND active = 1 ORDER BY id LIMIT 1', [$userId]) : null;
    }

    /** @return list<array> active contributors, for the editor's list */
    public static function all(bool $activeOnly = true): array
    {
        return db()->all('SELECT * FROM {p}contributors' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name');
    }

    public static function uniqueSlug(string $wanted, ?int $exceptId = null): string
    {
        $base = slugify($wanted, 90) ?: 'contributor';
        $slug = $base;
        $n = 2;
        while (db()->value('SELECT id FROM {p}contributors WHERE slug = ?' . ($exceptId ? ' AND id <> ' . (int)$exceptId : ''), [$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /**
     * Create or update a contributor from form-like data. Web addresses that are
     * not full https:// addresses are dropped.
     * @return int the contributor id
     */
    public static function save(?int $id, array $d): int
    {
        $web = static fn($v) => is_web_url(trim((string)$v)) ? mb_substr(trim((string)$v), 0, 500) : '';
        $row = [
            'name'            => mb_substr(trim((string)($d['name'] ?? '')), 0, 120),
            'designation'     => mb_substr(trim((string)($d['designation'] ?? '')), 0, 160),
            'company'         => mb_substr(trim((string)($d['company'] ?? '')), 0, 160),
            'company_url'     => $web($d['company_url'] ?? ''),
            'linkedin_url'    => $web($d['linkedin_url'] ?? ''),
            'bio'             => trim(mb_substr((string)($d['bio'] ?? ''), 0, 1500)) ?: null,
            'email'           => filter_var(trim((string)($d['email'] ?? '')), FILTER_VALIDATE_EMAIL) ? strtolower(trim((string)$d['email'])) : '',
            'phone'           => mb_substr(trim((string)($d['phone'] ?? '')), 0, 40),
            'photo_id'        => (int)($d['photo_id'] ?? 0) ?: null,
            'company_logo_id' => (int)($d['company_logo_id'] ?? 0) ?: null,
            'user_id'         => (int)($d['user_id'] ?? 0) ?: null,
            'active'          => array_key_exists('active', $d) ? (int)(bool)$d['active'] : 1,
        ];
        if ($row['name'] === '') {
            throw new InvalidArgumentException('A contributor needs a name.');
        }
        if ($id) {
            db()->update('contributors', $row, 'id = ?', [$id]);
        } else {
            $row['slug'] = self::uniqueSlug($row['name']);
            $id = db()->insert('contributors', $row);
        }
        Cache::clear();
        return (int)$id;
    }

    /** "Chief Engineer, Lucky Cement" — the line under a contributor's name. */
    public static function role(array $c): string
    {
        $d = trim((string)($c['designation'] ?? $c['contributor_designation'] ?? ''));
        $co = trim((string)($c['company'] ?? $c['contributor_company'] ?? ''));
        return $d !== '' && $co !== '' ? $d . ', ' . $co : ($d . $co);
    }

    // ------------------------------------------------------------------
    // "Write for us"
    // ------------------------------------------------------------------

    /** @return array{ok:bool, error?:string} */
    public static function submit(array $post): array
    {
        if (!self::writeOpen()) {
            return ['ok' => false, 'error' => 'closed'];
        }
        if (Reader::looksLikeRobot($post)) {
            return ['ok' => true];   // say nothing to robots
        }
        // One-line fields: no line breaks or control characters.
        $str = static fn(string $k, int $max) => trim(mb_substr((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_string($post[$k] ?? null) ? $post[$k] : ''), 0, $max));
        $name = $str('name', 120);
        $email = strtolower($str('email', 190));
        $title = $str('title', 255);
        $body = trim(mb_substr(str_replace("\r\n", "\n", is_string($post['body'] ?? null) ? $post['body'] : ''), 0, 30000));
        if ($name === '' || $title === '' || mb_strlen($body) < 40 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $str('designation', 160) === '' || $str('company', 160) === '') {
            return ['ok' => false, 'error' => 'missing'];
        }
        if (empty($post['consent'])) {
            return ['ok' => false, 'error' => 'consent'];
        }
        if (!RateLimit::hit('write:' . ip_hash(), 3, 3600)) {
            return ['ok' => false, 'error' => 'too_many'];
        }
        $web = static fn($v) => is_web_url(trim((string)$v)) ? mb_substr(trim((string)$v), 0, 500) : '';
        // Many links in a proposal is the usual sign of advertising robots.
        $status = preg_match_all('#https?://#i', $body) > 6 ? 'spam' : 'new';
        $id = db()->insert('submissions', [
            'name' => $name, 'email' => $email, 'phone' => $str('phone', 40), 'designation' => $str('designation', 160),
            'company' => $str('company', 160), 'company_url' => $web($post['company_url'] ?? ''), 'linkedin_url' => $web($post['linkedin_url'] ?? ''),
            'kind' => ($post['kind'] ?? '') === 'draft' ? 'draft' : 'outline', 'title' => $title, 'body' => $body, 'status' => $status,
            'consent_text' => mb_substr((string)Settings::get('write_consent', ''), 0, 500), 'ip_hash' => ip_hash(),
        ]);
        if ($status === 'new') {
            self::notify($id, $name, $title);
            $event = Event::name();
            Mailer::queue($email, t('write_mail_subject', ['event' => $event]),
                t('write_mail_body', ['name' => $name, 'title' => $title, 'event' => $event]));
            Mailer::flush(3);
        }
        Activity::log('submission.received', $title, ['id' => $id, 'status' => $status]);
        return ['ok' => true];
    }

    private static function notify(int $id, string $name, string $title): void
    {
        $list = (string)Settings::get('write_notify', '') ?: (string)Event::get('contact_email');
        $to = array_filter(array_map('trim', preg_split('/[,;\s]+/', $list) ?: []), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        $link = local_url('admin/') . '?p=submissions&id=' . $id;
        foreach (array_slice(array_unique($to), 0, 5) as $addr) {
            Mailer::queue($addr, 'New "Write for us" proposal: ' . $title,
                "$name sent a proposal for the blog:\n\n$title\n\nRead it and decide in the blog admin:\n$link\n");
        }
    }

    /**
     * Turn a proposal into a draft article by its writer (creating or reusing
     * their contributor profile). Returns the article id.
     */
    public static function accept(int $submissionId): int
    {
        $s = db()->one('SELECT * FROM {p}submissions WHERE id = ?', [$submissionId]);
        if (!$s) {
            throw new InvalidArgumentException('Proposal not found.');
        }
        if ($s['article_id'] && Article::find((int)$s['article_id'])) {
            return (int)$s['article_id'];
        }
        $cid = (int)$s['contributor_id'];
        if (!$cid && $s['email'] !== '') {
            $cid = (int)db()->value('SELECT id FROM {p}contributors WHERE email = ? ORDER BY id LIMIT 1', [$s['email']]);
        }
        if (!$cid) {
            $cid = self::save(null, $s);
        }
        $paras = array_filter(array_map('trim', preg_split("/\n{2,}/", (string)$s['body']) ?: []));
        $html = implode('', array_map(static fn($p) => '<p>' . nl2br(e($p), false) . '</p>', $paras));
        $articleId = Article::save(null, [
            'title' => $s['title'], 'body' => $html, 'author_id' => Auth::id(), 'contributor_id' => $cid,
            'review_note' => 'From "Write for us" (' . ($s['kind'] === 'draft' ? 'full draft' : 'outline') . ') by ' . $s['name'] . ', ' . $s['email'] . '.',
        ], [], 'Created from a "Write for us" proposal');
        db()->update('submissions', ['status' => 'accepted', 'article_id' => $articleId, 'contributor_id' => $cid, 'handled_by' => Auth::id()], 'id = ?', [$submissionId]);
        Activity::log('submission.accepted', (string)$s['title'], ['id' => $submissionId, 'article' => $articleId]);
        return $articleId;
    }
}
