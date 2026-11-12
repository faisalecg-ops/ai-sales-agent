<?php
declare(strict_types=1);

/**
 * Test harness. Not part of the deployed application.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// The tests empty the database they run on. They must never run on a live
// installation (config 'environment' => 'production'), nor from a browser.
// /social's tests load this file too, so the same guard protects them.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("The tests run from the command line only.\n");
}
if (APP_ENV === 'production') {
    fwrite(STDERR, "Refusing to run: config/config.php says this is a production installation, and the "
        . "tests would empty its database. Run them on a test copy whose config says 'development'.\n");
    exit(1);
}

/** A provider whose replies the test script controls. */
final class MockLlmProvider extends BaseLlmProvider
{
    public array $queue = [];
    public array $calls = [];
    public ?string $forceStatus = null;
    /** Answer "overloaded" this many times before behaving normally. */
    public int $busyTimes = 0;

    public function name(): string { return 'mock'; }
    public function isConfigured(): bool { return true; }

    public function push(array $structured): void
    {
        $this->queue[] = json_encode($structured);
    }

    public function pushRaw(string $raw): void
    {
        $this->queue[] = $raw;
    }

    public function complete(string $system, array $messages, array $options): array
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages, 'options' => $options];

        if ($this->busyTimes > 0) {
            $this->busyTimes--;
            return $this->result(false, null, 'overloaded', 'overloaded (test)', 'mock-model');
        }
        if ($this->forceStatus !== null) {
            return $this->result(false, null, $this->forceStatus, 'forced by test', 'mock-model');
        }
        if (!$this->queue) {
            return $this->result(true, json_encode(['reply' => 'Default mock reply.']), 'ok', null, 'mock-model', 10, 10, 5);
        }
        return $this->result(true, array_shift($this->queue), 'ok', null, 'mock-model', 100, 40, 12);
    }

    public function lastSystemPrompt(): string
    {
        return $this->calls ? (string)end($this->calls)['system'] : '';
    }

    /** Everything sent to the model in the last call, apart from the system prompt. */
    public function lastUserContent(): string
    {
        return $this->calls ? json_encode(end($this->calls)['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }

    public function reset(): void
    {
        $this->queue = [];
        $this->calls = [];
        $this->forceStatus = null;
        $this->busyTimes = 0;
    }
}

/**
 * A messaging service that records rather than sends.
 *
 * Installed for the whole test run, so nothing in this suite can put a real
 * message on a real phone even if a test is wrong.
 */
final class MockMessagingProvider extends BaseMessagingProvider
{
    /** @var array<int,array{to:string,body:string}> */
    public array $sent = [];
    public bool $configured = true;
    public ?string $failWith = null;
    public int $counter = 0;

    public function name(): string { return 'mock-wa'; }

    public function isConfigured(): bool { return $this->configured; }

    public function send(string $toE164, string $body, array $options = []): array
    {
        if ($this->failWith !== null) {
            return $this->result(false, null, 'rejected', $this->failWith);
        }
        $this->sent[] = ['to' => $toE164, 'body' => $body];
        return $this->result(true, 'mock-ref-' . (++$this->counter), 'ok');
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->failWith = null;
        $this->configured = true;
    }

    public function lastBody(): string
    {
        return $this->sent ? (string)end($this->sent)['body'] : '';
    }
}

/**
 * A research service that answers from prepared pages, and counts what it
 * was asked. Installed for the research tests, so no real search is made.
 */
final class MockResearchProvider extends BaseResearchProvider
{
    /** @var array<int,array{title:string,url:string,content:string}> */
    public array $pages = [];
    public array $queries = [];
    public bool $configured = true;
    public ?string $failWith = null;

    public function name(): string { return 'mock-research'; }

    public function isConfigured(): bool { return $this->configured; }

    public function search(string $query, array $options = []): array
    {
        $this->queries[] = ['query' => $query, 'options' => $options];
        if ($this->failWith !== null) {
            return $this->result(false, [], 0, $this->failWith);
        }
        return $this->result(true, $this->pages, 1);
    }
}

/** A provider that returns prepared document-extraction output. */
final class MockExtractProvider extends BaseLlmProvider
{
    /** @var array<int,array> findings returned for each successive call */
    public array $batches = [];
    public ?string $forceStatus = null;
    public int $calls = 0;

    public function name(): string { return 'mock-extract'; }
    public function isConfigured(): bool { return true; }

    public function complete(string $system, array $messages, array $options): array
    {
        $this->calls++;
        if ($this->forceStatus !== null) {
            return $this->result(false, null, $this->forceStatus, 'forced by test', 'mock');
        }
        $findings = $this->batches ? array_shift($this->batches) : [];
        return $this->result(
            true,
            json_encode(['findings' => $findings]),
            'ok', null, 'mock', 500, 200, 10
        );
    }
}

// ---------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------
final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function ok(bool $condition, string $label, string $detail = ''): void
    {
        if ($condition) {
            self::$pass++;
            echo "  \033[32mPASS\033[0m  " . $label . "\n";
        } else {
            self::$fail++;
            self::$failures[] = self::$group . ' / ' . $label . ($detail ? ' — ' . $detail : '');
            echo "  \033[31mFAIL\033[0m  " . $label . ($detail ? "  (" . $detail . ")" : '') . "\n";
        }
    }

    public static function equals(mixed $expected, mixed $actual, string $label): void
    {
        self::ok($expected === $actual, $label, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public static function summary(): int
    {
        echo "\n" . str_repeat('-', 62) . "\n";
        echo "Passed: " . self::$pass . "   Failed: " . self::$fail . "\n";
        if (self::$failures) {
            echo "\nFailures:\n";
            foreach (self::$failures as $f) {
                echo "  - " . $f . "\n";
            }
        }
        return self::$fail === 0 ? 0 : 1;
    }
}

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------
function reset_database(): void
{
    // The test database is built from schema.sql, so anything added later
    // has to be applied before the tables can be cleared. Idempotent, so the
    // cost after the first run is a handful of lookups.
    Migrations::run('tests');

    $tables = [
        'messages', 'conversations', 'leads', 'geo_lookups', 'request_log', 'rate_events',
        'audit_log', 'usage_daily', 'email_outbox', 'agent_provider_health',
        'review_actions', 'knowledge_review_tokens', 'fallback_messages',
        'stall_packages', 'sponsorship_packages', 'knowledge_articles', 'faq_entries',
        'reference_attachments',
        'import_proposals', 'import_questions', 'import_findings', 'import_chunks',
        'import_documents', 'import_runs',
        'whatsapp_outbox', 'whatsapp_inbox', 'agent_sales_reps', 'company_research', 'registrations',
        'outreach_emails', 'prospect_targets', 'prospect_campaigns',
        'company_contacts', 'company_evidence', 'companies', 'suppression_list', 'site_pages', 'quotations',
        'agents', 'admin_users', 'admin_user_access', 'settings', 'login_attempts',
    ];
    db()->raw()->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) {
        // A table only present after an upgrade is skipped rather than
        // failing the whole run, so the suite still works on a database
        // that has not been migrated yet.
        if (Migrations::tableExists($t)) {
            db()->raw()->query('TRUNCATE TABLE `' . $t . '`');
        }
    }
    db()->raw()->query('SET FOREIGN_KEY_CHECKS = 1');
}

function seed_agent(): array
{
    $agentId = db()->insert('agents', [
        'slug'            => 'buildasia',
        'name'            => 'Build Asia',
        'status'          => 'active',
        'website_url'     => 'https://www.buildasia.net',
        'allowed_domains' => "www.buildasia.net\nbuildasia.net",
        'persona'         => AgentDefaults::PERSONA,
        'sales_objectives' => AgentDefaults::OBJECTIVES,
        'sales_rules'     => AgentDefaults::SALES_RULES,
        'ai_disclosure_text' => AgentDefaults::DISCLOSURE,
        'llm_provider'    => 'mock',
        'llm_model'       => 'mock-model',
        'notify_email'    => 'sales@example.net',
        'readiness_min_signals' => 2,
    ]);
    Fallbacks::seed($agentId);

    // Approved international stall packages (USD only).
    db()->insert('stall_packages', [
        'agent_id' => $agentId, 'package_name' => 'Shell Scheme 9 sqm', 'package_type' => 'shell',
        'stall_size' => '3m x 3m', 'size_sqm' => 9, 'currency' => 'USD', 'price' => 3600,
        'region_group' => 'international', 'active' => 1, 'status' => 'approved',
        'included_items' => "Carpeted floor\nFascia with company name\n2 spotlights\n1 table and 2 chairs\nPower socket",
        'tax_included' => 'no', 'sort_order' => 1,
    ]);
    db()->insert('stall_packages', [
        'agent_id' => $agentId, 'package_name' => 'Shell Scheme 18 sqm', 'package_type' => 'shell',
        'stall_size' => '6m x 3m', 'size_sqm' => 18, 'currency' => 'USD', 'price' => 6900,
        'region_group' => 'international', 'active' => 1, 'status' => 'approved',
        'included_items' => "Carpeted floor\nFascia with company name\n4 spotlights\n1 table and 4 chairs",
        'tax_included' => 'unspecified', 'sort_order' => 2,
    ]);
    db()->insert('stall_packages', [
        'agent_id' => $agentId, 'package_name' => 'Bare Space 36 sqm', 'package_type' => 'bare',
        'stall_size' => '6m x 6m', 'size_sqm' => 36, 'currency' => 'USD', 'price' => 10800,
        'region_group' => 'international', 'active' => 1, 'status' => 'approved',
        'included_items' => "Floor space only\nPower connection point",
        'tax_included' => 'no', 'sort_order' => 3,
    ]);
    // Draft record: must never be visible.
    db()->insert('stall_packages', [
        'agent_id' => $agentId, 'package_name' => 'Premium Corner 27 sqm', 'package_type' => 'shell',
        'stall_size' => '9m x 3m', 'currency' => 'USD', 'price' => 9500,
        'region_group' => 'international', 'active' => 1, 'status' => 'draft',
    ]);
    // Expired record: must never be visible.
    db()->insert('stall_packages', [
        'agent_id' => $agentId, 'package_name' => 'Early Bird 9 sqm', 'package_type' => 'shell',
        'stall_size' => '3m x 3m', 'currency' => 'USD', 'price' => 3100,
        'region_group' => 'international', 'active' => 1, 'status' => 'approved',
        'valid_from' => '2020-01-01', 'valid_until' => '2020-12-31',
    ]);

    db()->insert('sponsorship_packages', [
        'agent_id' => $agentId, 'tier_name' => 'Platinum Sponsor', 'currency' => 'USD', 'price' => 25000,
        'benefits' => "Logo on all event branding\nSpeaking slot at the opening session\n36 sqm stand included\nFull delegate list",
        'quantity_available' => 1, 'region_group' => 'international', 'active' => 1, 'status' => 'approved',
        'tax_included' => 'no', 'sort_order' => 1,
    ]);

    db()->insert('faq_entries', [
        'agent_id' => $agentId, 'question' => 'When and where does Build Asia take place?',
        'answer' => 'Build Asia takes place at the Karachi Expo Centre. Exact dates are confirmed with each edition.',
        'keywords' => 'dates venue when where location', 'status' => 'approved', 'active' => 1,
    ]);
    db()->insert('knowledge_articles', [
        'agent_id' => $agentId, 'title' => 'Why exhibit at Build Asia',
        'category' => 'benefits',
        'body' => 'Build Asia brings together manufacturers, distributors and contractors from the construction and building materials sector. Exhibitors meet buyers, appoint distributors and launch products to a trade audience.',
        'keywords' => 'benefits why exhibit audience buyers distributors',
        'stage_tags' => 'education,business_qualification', 'status' => 'approved', 'active' => 1,
    ]);

    return db()->one('SELECT * FROM agents WHERE id = ?', [$agentId]) ?? [];
}

/** Start a conversation and return [conversation, secret]. */
function start_conversation(array $agent, ?string $visitorId = null): array
{
    $started = ConversationStore::start($agent, [
        'visitor_id' => $visitorId,
        'origin'     => 'www.buildasia.net',
        'page_url'   => 'https://www.buildasia.net/exhibit',
    ]);
    return [$started['conversation'], $started['session_secret']];
}

/** Send one visitor turn through the orchestrator. */
function say(array $agent, array $conversation, string $secret, string $message): array
{
    return ChatOrchestrator::handle($agent, [
        'request_id'     => 'r' . bin2hex(random_bytes(10)),
        'session_id'     => (string)$conversation['session_id'],
        'session_secret' => $secret,
        'visitor_id'     => $conversation['visitor_id'],
        'message'        => $message,
        'origin'         => 'www.buildasia.net',
    ]);
}

function reload_conversation(array $conversation): array
{
    return db()->one('SELECT * FROM conversations WHERE id = ?', [(int)$conversation['id']]) ?? $conversation;
}

/** Flatten response blocks into plain text for assertions. */
function blocks_text(array $response): string
{
    $out = '';
    foreach (($response['blocks'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'p') {
            foreach ($b['spans'] as $s) {
                $out .= $s['v'] . ' ';
            }
        } else {
            foreach (($b['items'] ?? []) as $item) {
                foreach ($item as $s) {
                    $out .= $s['v'] . ' ';
                }
            }
        }
    }
    return trim($out);
}
