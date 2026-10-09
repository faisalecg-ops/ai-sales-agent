<?php
declare(strict_types=1);

/**
 * The lifecycle of one document import.
 *
 * The stages run in order: collecting -> extracting -> analysing ->
 * reconciling -> clarifying -> proposing -> review -> published.
 *
 * Each stage does a bounded amount of work per request. That is not a style
 * choice: shared hosting kills a script after thirty seconds or so, and there
 * is no background worker to hand the job to. So the browser drives it, one
 * step at a time, and the run can be closed and resumed without losing
 * anything.
 */
final class ImportRun
{
    /** Uploads accepted in a single run. */
    public const MAX_DOCUMENTS = 12;

    /** What we would accept if the server allowed it. */
    public const MAX_BYTES_WANTED = 12582912;   // 12 MB

    /** Chunks analysed per request. One model call each, so this stays low. */
    public const CHUNKS_PER_PASS = 1;

    public const STAGES = [
        'collecting'  => 'Waiting for documents',
        'extracting'  => 'Reading the documents',
        'analysing'   => 'Working out what they say',
        'reconciling' => 'Comparing the documents against each other',
        'clarifying'  => 'Waiting for your answers',
        'proposing'   => 'Drafting the knowledge base',
        'review'      => 'Ready for you to review',
        'published'   => 'Added as drafts',
        'cancelled'   => 'Cancelled',
        'failed'      => 'Stopped with a problem',
    ];

    // -----------------------------------------------------------------
    // Creating and loading
    // -----------------------------------------------------------------

    public static function create(
        array $agent,
        int $adminId,
        ?string $title = null,
        ?string $provider = null,
        ?string $model = null
    ): array {
        // Default to whatever is actually usable, preferring the agent's own
        // provider. Starting an import that cannot run is a pointless failure
        // when another provider is sitting there configured.
        $chosen = LlmCatalogue::firstConfigured($provider ?? (string)$agent['llm_provider']);

        $id = db()->insert('import_runs', [
            'agent_id'   => (int)$agent['id'],
            'created_by' => $adminId,
            'title'      => Validator::text($title, 190) ?? ('Import ' . gmdate('j M Y, H:i')),
            'status'     => 'collecting',
            'llm_provider' => $chosen,
            'llm_model'    => Validator::text($model, 96)
                ?: ($chosen !== null ? (LlmCatalogue::defaultModel($chosen) ?: null) : null),
        ]);

        Audit::log('import_started', (int)$agent['id'], 'import_run', $id, 'Document import created');

        return self::load($id, (int)$agent['id']) ?? [];
    }

    public static function load(int $id, int $agentId): ?array
    {
        return db()->one(
            'SELECT * FROM import_runs WHERE id = ? AND agent_id = ? LIMIT 1',
            [$id, $agentId]
        ) ?: null;
    }

    /** The run currently being worked on, if there is one. */
    public static function active(int $agentId): ?array
    {
        return db()->one(
            "SELECT * FROM import_runs
             WHERE agent_id = ? AND status NOT IN ('published','cancelled','failed')
             ORDER BY id DESC LIMIT 1",
            [$agentId]
        ) ?: null;
    }

    public static function recent(int $agentId, int $limit = 10): array
    {
        return db()->all(
            'SELECT * FROM import_runs WHERE agent_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            [$agentId]
        );
    }

    /**
     * Which AI will read the documents, and with which model.
     *
     * Falls back to the agent's provider for runs created before this was a
     * choice, so an older import still works.
     *
     * @return array{provider:string,model:string}
     */
    public static function reader(array $run, array $agent): array
    {
        $provider = trim((string)($run['llm_provider'] ?? ''));
        if ($provider === '' || !LlmCatalogue::has($provider)) {
            $provider = (string)$agent['llm_provider'];
        }

        $model = trim((string)($run['llm_model'] ?? ''));
        if ($model === '') {
            $model = $provider === (string)$agent['llm_provider']
                ? (string)$agent['llm_model']
                : LlmCatalogue::defaultModel($provider);
        }

        return ['provider' => $provider, 'model' => $model];
    }

    /** Change which AI reads the documents, before or between steps. */
    public static function setReader(array $run, ?string $provider, ?string $model): bool
    {
        if ($provider !== null && !LlmCatalogue::has($provider)) {
            return false;
        }

        db()->update('import_runs', [
            'llm_provider' => $provider,
            'llm_model'    => Validator::text($model, 96)
                ?: ($provider !== null ? (LlmCatalogue::defaultModel($provider) ?: null) : null),
        ], 'id = ?', [(int)$run['id']]);

        return true;
    }

    public static function setStatus(int $runId, string $status, ?string $detail = null): void
    {
        $fields = ['status' => $status, 'stage_detail' => $detail];
        if (in_array($status, ['published', 'cancelled', 'failed'], true)) {
            $fields['completed_at'] = gmdate('Y-m-d H:i:s');
        }
        db()->update('import_runs', $fields, 'id = ?', [$runId]);
    }

    public static function fail(int $runId, string $reason): void
    {
        db()->update('import_runs', [
            'status'     => 'failed',
            'last_error' => mb_substr($reason, 0, 500),
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$runId]);
        app_log('import', 'run failed', ['run' => $runId, 'reason' => $reason]);
    }

    /**
     * Pick a failed run back up.
     *
     * Failures here are nearly always something the operator can put right —
     * a missing API key, a provider outage — so the run resumes from wherever
     * it got to rather than making them upload everything again.
     */
    public static function resume(array $run): string
    {
        $runId = (int)$run['id'];

        $unread = (int)db()->value(
            "SELECT COUNT(*) FROM import_documents WHERE run_id = ? AND extraction_status = 'pending'",
            [$runId]
        );
        $chunks = (int)db()->value('SELECT COUNT(*) FROM import_chunks WHERE run_id = ?', [$runId]);
        $unanalysed = (int)db()->value(
            "SELECT COUNT(*) FROM import_chunks WHERE run_id = ? AND status <> 'done'",
            [$runId]
        );

        // Sections that were skipped after repeated failures get another go.
        db()->exec(
            "UPDATE import_chunks SET status = 'pending', attempts = 0, last_error = NULL
             WHERE run_id = ? AND status = 'failed'",
            [$runId]
        );

        $status = match (true) {
            $chunks === 0 && $unread === 0 => 'collecting',
            $unread > 0                    => 'extracting',
            $unanalysed > 0                => 'analysing',
            default                        => 'reconciling',
        };

        db()->update('import_runs', [
            'status'       => $status,
            'stage_detail' => 'Picked up again.',
            'last_error'   => null,
            'completed_at' => null,
        ], 'id = ?', [$runId]);

        return $status;
    }

    public static function cancel(array $run, string $actor): void
    {
        self::setStatus((int)$run['id'], 'cancelled', 'Cancelled by ' . $actor);
        Audit::log('import_cancelled', (int)$run['agent_id'], 'import_run', (int)$run['id'], 'Cancelled by ' . $actor);
    }

    public static function countTokens(int $runId, int $in, int $out): void
    {
        db()->exec(
            'UPDATE import_runs SET input_tokens = input_tokens + ?, output_tokens = output_tokens + ? WHERE id = ?',
            [max(0, $in), max(0, $out), $runId]
        );
    }

    // -----------------------------------------------------------------
    // Documents
    // -----------------------------------------------------------------

    /**
     * Accept an uploaded file.
     *
     * Validation is deliberately strict and happens before anything is
     * stored: extension against an allowlist, real size, and a content sniff
     * so a renamed executable cannot arrive as a "document". Files are kept
     * outside the web root under a random name and are never served back.
     *
     * @param array $file one entry from $_FILES
     * @return array{ok:bool,error:?string,document_id:?int}
     */
    public static function addDocument(array $run, array $file, array $meta = []): array
    {
        $runId   = (int)$run['id'];
        $agentId = (int)$run['agent_id'];

        $existing = (int)db()->value('SELECT COUNT(*) FROM import_documents WHERE run_id = ?', [$runId]);
        if ($existing >= self::MAX_DOCUMENTS) {
            return self::uploadError('You can add up to ' . self::MAX_DOCUMENTS . ' documents in one go.');
        }

        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code !== UPLOAD_ERR_OK) {
            return self::uploadError(match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'That file is larger than this server accepts. ' . self::uploadLimitAdvice(),
                UPLOAD_ERR_PARTIAL   => 'The upload did not finish. Try again.',
                UPLOAD_ERR_NO_FILE   => 'No file was chosen.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                    'The server could not store the upload. Check the storage folder is writable.',
                default              => 'The upload failed.',
            });
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return self::uploadError('That upload could not be verified.');
        }

        $originalName = trim((string)($file['name'] ?? 'document'));
        $extension    = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

        if (!isset(DocumentText::ACCEPTED[$extension])) {
            return self::uploadError(
                'That file type is not supported. Accepted types: '
                . implode(', ', array_keys(DocumentText::ACCEPTED)) . '.'
            );
        }

        $size = (int)filesize($tmp);
        if ($size <= 0) {
            return self::uploadError('That file appears to be empty.');
        }
        $limit = self::uploadLimitBytes();
        if ($size > $limit) {
            return self::uploadError(
                'That file is ' . self::readableSize($size) . ', and this server accepts up to '
                . self::readableSize($limit) . '. '
                . (self::uploadLimitIsServerImposed() ? self::uploadLimitAdvice() : '')
            );
        }

        // Sniff the real content type and require it to match the extension.
        $mime = self::detectMime($tmp);
        if (!self::mimeMatchesExtension($mime, $extension)) {
            return self::uploadError(
                'That file does not look like a ' . DocumentText::ACCEPTED[$extension]
                . ' inside. Check you uploaded the right file.'
            );
        }

        $hash = (string)hash_file('sha256', $tmp);
        $dupe = db()->one(
            'SELECT original_name FROM import_documents WHERE run_id = ? AND sha256 = ? LIMIT 1',
            [$runId, $hash]
        );
        if ($dupe) {
            return self::uploadError(
                'That is the same file as "' . (string)$dupe['original_name'] . '", which is already added.'
            );
        }

        $dir = rtrim(STORAGE_PATH, '/') . '/uploads/imports';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return self::uploadError('The storage folder could not be created.');
        }

        $stored = 'run' . $runId . '-' . bin2hex(random_bytes(10)) . '.' . $extension;
        if (!@move_uploaded_file($tmp, $dir . '/' . $stored)) {
            return self::uploadError('The file could not be saved to storage.');
        }
        @chmod($dir . '/' . $stored, 0640);

        $documentId = db()->insert('import_documents', [
            'run_id'        => $runId,
            'agent_id'      => $agentId,
            'original_name' => mb_substr($originalName, 0, 255),
            'stored_name'   => $stored,
            'extension'     => $extension,
            'mime_type'     => $mime,
            'byte_size'     => $size,
            'sha256'        => $hash,
            'doc_kind'      => Validator::inList($meta['doc_kind'] ?? null, self::documentKinds(), 'unknown'),
            'edition_label' => Validator::text($meta['edition_label'] ?? null, 160),
            'authority'     => self::authorityFor((string)($meta['doc_kind'] ?? 'unknown')),
            'published_on'  => Validator::date($meta['published_on'] ?? null),
        ]);

        self::refreshCounts($runId);

        return ['ok' => true, 'error' => null, 'document_id' => $documentId];
    }

    private static function uploadError(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'document_id' => null];
    }

    public static function documentKinds(): array
    {
        return ['unknown', 'brochure', 'price_list', 'sponsorship', 'post_show_report', 'terms', 'floor_plan', 'other'];
    }

    public static function documentKindLabels(): array
    {
        return [
            'unknown'          => 'Not sure / mixed',
            'price_list'       => 'Price list or participation rates',
            'sponsorship'      => 'Sponsorship or partnership packages',
            'brochure'         => 'Brochure or marketing document',
            'post_show_report' => 'Post-show report (results of a past edition)',
            'terms'            => 'Terms and conditions',
            'floor_plan'       => 'Floor plan',
            'other'            => 'Something else',
        ];
    }

    /**
     * How much weight a document's claims carry.
     *
     * A price list beats a brochure on pricing, because brochures are
     * written earlier and go out of date first. This only ever settles a
     * tie automatically when the difference is small and the gap in
     * authority is wide; anything material still goes to the operator.
     */
    private static function authorityFor(string $kind): int
    {
        return match ($kind) {
            'price_list'       => 8,
            'sponsorship'      => 8,
            'terms'            => 7,
            'post_show_report' => 6,
            'brochure'         => 4,
            'floor_plan'       => 3,
            default            => 5,
        };
    }

    public static function documents(int $runId): array
    {
        return db()->all(
            'SELECT * FROM import_documents WHERE run_id = ? ORDER BY id ASC',
            [$runId]
        );
    }

    public static function document(int $documentId, int $runId): ?array
    {
        return db()->one(
            'SELECT * FROM import_documents WHERE id = ? AND run_id = ? LIMIT 1',
            [$documentId, $runId]
        ) ?: null;
    }

    public static function removeDocument(array $run, int $documentId): bool
    {
        $doc = self::document($documentId, (int)$run['id']);
        if (!$doc) {
            return false;
        }
        $path = self::storedPath($doc);
        if (is_file($path)) {
            @unlink($path);
        }
        db()->exec('DELETE FROM import_documents WHERE id = ?', [$documentId]);
        self::refreshCounts((int)$run['id']);
        return true;
    }

    public static function storedPath(array $document): string
    {
        return rtrim(STORAGE_PATH, '/') . '/uploads/imports/' . (string)$document['stored_name'];
    }

    /** Delete the stored files once a run is finished with. */
    public static function purgeFiles(int $runId): int
    {
        $removed = 0;
        foreach (self::documents($runId) as $doc) {
            $path = self::storedPath($doc);
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    // -----------------------------------------------------------------
    // Extraction
    // -----------------------------------------------------------------

    /**
     * Read the next document that has not been read yet.
     *
     * @return array{done:bool,document:?array,result:?array}
     */
    public static function extractNext(array $run): array
    {
        $runId = (int)$run['id'];

        $doc = db()->one(
            "SELECT * FROM import_documents
             WHERE run_id = ? AND extraction_status = 'pending'
             ORDER BY id ASC LIMIT 1",
            [$runId]
        );

        if (!$doc) {
            return ['done' => true, 'document' => null, 'result' => null];
        }

        $result = DocumentText::extract(self::storedPath($doc), (string)$doc['extension']);

        $status = $result['ok'] ? 'ok' : ($result['chars'] > 0 ? 'empty' : 'failed');

        db()->update('import_documents', [
            'extraction_status'  => $status,
            'extraction_method'  => mb_substr((string)$result['method'], 0, 80),
            'extraction_problem' => $result['problem'] !== null
                ? mb_substr(trim((string)$result['problem'] . ' ' . (string)$result['advice']), 0, 500)
                : null,
            'extracted_chars'    => (int)$result['chars'],
            'page_count'         => $result['pages'],
            'text_body'          => $result['ok'] ? mb_substr($result['text'], 0, 2000000) : null,
        ], 'id = ?', [(int)$doc['id']]);

        if ($result['ok']) {
            $chunks = DocumentText::chunk($result['text']);
            foreach ($chunks as $chunk) {
                db()->insert('import_chunks', [
                    'run_id'      => $runId,
                    'document_id' => (int)$doc['id'],
                    'chunk_index' => (int)$chunk['index'],
                    'char_offset' => (int)$chunk['offset'],
                    'body'        => $chunk['text'],
                ]);
            }
        }

        self::refreshCounts($runId);

        return ['done' => false, 'document' => $doc, 'result' => $result];
    }

    /** Text the operator pasted in, treated as a document in its own right. */
    public static function addPastedText(array $run, string $title, string $text, array $meta = []): array
    {
        $text = DocumentText::tidy($text);
        if (mb_strlen($text) < 40) {
            return self::uploadError('That is too short to be worth analysing.');
        }

        $runId = (int)$run['id'];
        $name  = Validator::text($title, 200) ?? 'Pasted text';

        $documentId = db()->insert('import_documents', [
            'run_id'            => $runId,
            'agent_id'          => (int)$run['agent_id'],
            'original_name'     => $name,
            'stored_name'       => 'pasted-' . bin2hex(random_bytes(6)),
            'extension'         => 'txt',
            'mime_type'         => 'text/plain',
            'byte_size'         => strlen($text),
            'sha256'            => hash('sha256', $text),
            'doc_kind'          => Validator::inList($meta['doc_kind'] ?? null, self::documentKinds(), 'unknown'),
            'edition_label'     => Validator::text($meta['edition_label'] ?? null, 160),
            'authority'         => self::authorityFor((string)($meta['doc_kind'] ?? 'unknown')),
            'extraction_status' => 'ok',
            'extraction_method' => 'pasted in directly',
            'extracted_chars'   => mb_strlen($text),
            'text_body'         => $text,
        ]);

        foreach (DocumentText::chunk($text) as $chunk) {
            db()->insert('import_chunks', [
                'run_id'      => $runId,
                'document_id' => $documentId,
                'chunk_index' => (int)$chunk['index'],
                'char_offset' => (int)$chunk['offset'],
                'body'        => $chunk['text'],
            ]);
        }

        self::refreshCounts($runId);

        return ['ok' => true, 'error' => null, 'document_id' => $documentId];
    }

    // -----------------------------------------------------------------
    // Progress
    // -----------------------------------------------------------------

    public static function refreshCounts(int $runId): void
    {
        $docs = db()->one(
            "SELECT COUNT(*) AS total,
                    SUM(extraction_status <> 'pending') AS done
             FROM import_documents WHERE run_id = ?",
            [$runId]
        ) ?: ['total' => 0, 'done' => 0];

        $chunks = db()->one(
            "SELECT COUNT(*) AS total, SUM(status = 'done') AS done
             FROM import_chunks WHERE run_id = ?",
            [$runId]
        ) ?: ['total' => 0, 'done' => 0];

        db()->update('import_runs', [
            'documents_total'    => (int)$docs['total'],
            'documents_done'     => (int)($docs['done'] ?? 0),
            'chunks_total'       => (int)$chunks['total'],
            'chunks_done'        => (int)($chunks['done'] ?? 0),
            'findings_total'     => (int)db()->value('SELECT COUNT(*) FROM import_findings WHERE run_id = ?', [$runId]),
            'questions_total'    => (int)db()->value('SELECT COUNT(*) FROM import_questions WHERE run_id = ?', [$runId]),
            'questions_answered' => (int)db()->value("SELECT COUNT(*) FROM import_questions WHERE run_id = ? AND status <> 'open'", [$runId]),
            'proposals_total'    => (int)db()->value('SELECT COUNT(*) FROM import_proposals WHERE run_id = ?', [$runId]),
        ], 'id = ?', [$runId]);
    }

    /**
     * A plain-English description of where the run has got to.
     *
     * @return array{percent:int,label:string,detail:string}
     */
    public static function progress(array $run): array
    {
        $status = (string)$run['status'];

        [$percent, $detail] = match ($status) {
            'collecting' => [5, (int)$run['documents_total'] === 0
                ? 'Add the documents you want the assistant to learn from.'
                : (int)$run['documents_total'] . self::plural((int)$run['documents_total'], ' document', ' documents') . ' ready.'],
            'extracting' => [
                (int)$run['documents_total'] > 0
                    ? (int)(10 + 20 * ((int)$run['documents_done'] / max(1, (int)$run['documents_total'])))
                    : 10,
                'Read ' . (int)$run['documents_done'] . ' of ' . (int)$run['documents_total'] . ' documents.',
            ],
            'analysing' => [
                (int)$run['chunks_total'] > 0
                    ? (int)(30 + 35 * ((int)$run['chunks_done'] / max(1, (int)$run['chunks_total'])))
                    : 35,
                'Analysed ' . (int)$run['chunks_done'] . ' of ' . (int)$run['chunks_total'] . ' sections, '
                . (int)$run['findings_total'] . ' facts found so far.',
            ],
            'reconciling' => [70, 'Comparing what each document says.'],
            'clarifying'  => [
                (int)$run['questions_total'] > 0
                    ? (int)(75 + 15 * ((int)$run['questions_answered'] / max(1, (int)$run['questions_total'])))
                    : 80,
                (int)$run['questions_answered'] . ' of ' . (int)$run['questions_total'] . ' questions answered.',
            ],
            'proposing' => [92, 'Assembling the knowledge base.'],
            'review'    => [96, (int)$run['proposals_total'] . self::plural((int)$run['proposals_total'], ' item', ' items') . ' ready for you to check.'],
            'published' => [100, (int)$run['published_total'] . self::plural((int)$run['published_total'], ' item was', ' items were') . ' added.'],
            'cancelled' => [0, 'This import was cancelled.'],
            'failed'    => [0, (string)($run['last_error'] ?? 'Something went wrong.')],
            default     => [0, ''],
        };

        return [
            'percent' => max(0, min(100, $percent)),
            'label'   => self::STAGES[$status] ?? $status,
            'detail'  => (string)($run['stage_detail'] ?: $detail),
        ];
    }

    /**
     * Is there work the server can do right now without the operator?
     * The page uses this to decide whether to keep stepping.
     */
    public static function hasPendingWork(array $run): bool
    {
        return in_array((string)$run['status'], ['extracting', 'analysing', 'reconciling', 'proposing'], true);
    }

    // -----------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------

    /**
     * What this server will actually accept.
     *
     * Our own cap is generous, but PHP's own limits usually bind first and on
     * shared hosting they are often left at 2 MB — smaller than a typical
     * exhibition brochure. Advertising a limit the server will not honour just
     * produces a failed upload and no explanation, so the real figure is
     * worked out and shown.
     */
    public static function uploadLimitBytes(): int
    {
        $limits = [self::MAX_BYTES_WANTED];

        $upload = self::iniBytes((string)ini_get('upload_max_filesize'));
        if ($upload > 0) {
            $limits[] = $upload;
        }

        // A post body carries the file plus the form fields, so leave headroom.
        $post = self::iniBytes((string)ini_get('post_max_size'));
        if ($post > 0) {
            $limits[] = max(1024, $post - 65536);
        }

        return (int)min($limits);
    }

    /** True when PHP, rather than this application, is the binding limit. */
    public static function uploadLimitIsServerImposed(): bool
    {
        return self::uploadLimitBytes() < self::MAX_BYTES_WANTED;
    }

    /** Plain-English guidance for raising a low server limit. */
    public static function uploadLimitAdvice(): string
    {
        return 'This server currently accepts uploads up to '
            . self::readableSize(self::uploadLimitBytes())
            . ', which is smaller than many brochures. To raise it in cPanel, open '
            . 'MultiPHP INI Editor, choose this domain, and set upload_max_filesize and '
            . 'post_max_size to 16M. If that tool is not available, paste the text of the '
            . 'document in instead — the result is exactly the same.';
    }

    /** Turn a php.ini shorthand size such as "8M" into bytes. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit   = strtolower($value[strlen($value) - 1]);
        $number = (float)$value;

        return (int)match ($unit) {
            'g'     => $number * 1073741824,
            'm'     => $number * 1048576,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /** Small helper so counted things read naturally in the interface. */
    public static function plural(int $count, string $one, string $many): string
    {
        return $count === 1 ? $one : $many;
    }

    public static function readableSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' bytes';
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string)finfo_file($finfo, $path);
                finfo_close($finfo);
                if ($mime !== '') {
                    return $mime;
                }
            }
        }
        return 'application/octet-stream';
    }

    /**
     * The sniffed type has to be consistent with the extension. Text-ish
     * formats are allowed to report as any text type, because servers
     * disagree about CSV and Markdown.
     */
    private static function mimeMatchesExtension(string $mime, string $extension): bool
    {
        $mime = strtolower($mime);

        return match ($extension) {
            'pdf'  => str_contains($mime, 'pdf'),
            'docx' => str_contains($mime, 'openxmlformats-officedocument')
                   || str_contains($mime, 'zip'),
            'rtf'  => str_contains($mime, 'rtf') || str_starts_with($mime, 'text/'),
            'html', 'htm' => str_contains($mime, 'html') || str_starts_with($mime, 'text/'),
            'txt', 'md', 'csv', 'tsv' => str_starts_with($mime, 'text/')
                   || $mime === 'application/csv'
                   || $mime === 'application/octet-stream',
            default => false,
        };
    }
}
