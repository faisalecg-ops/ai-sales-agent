<?php
declare(strict_types=1);

/**
 * Read-only access to the shared visitor registration database.
 *
 * All exhibitions register visitors into one table on a separate cPanel
 * account of the same server, so this connects directly rather than asking
 * that system to notify us. Three consequences shape the design:
 *
 *  - It is READ ONLY. This class issues nothing but SELECT, and the database
 *    user it connects as should be granted SELECT and nothing else. The
 *    registration system stays the owner of its own data.
 *  - Rows are collected by a watermark on the auto-incrementing id, so an
 *    hour of downtime catches up by itself and nothing is ever missed.
 *  - Reading the same rows twice is harmless, because the platform's own
 *    table refuses a duplicate source id.
 */
final class RegistrationSource
{
    /**
     * Their column names, mapped once here.
     *
     * Written out rather than guessed so that a change to their form is a
     * visible failure in one place instead of quietly importing blanks.
     */
    private const COLUMNS = [
        'id'          => 'id',
        'name'        => 'your_name',
        'designation' => 'designation',
        'website'     => 'web',
        'company'     => 'c_name',
        'email'       => 'uemail',
        'phone'       => 'cell',
        'address'     => 'address',
        'city'        => 'city',
        'country'     => 'country',
        'event_id'    => 'event_id',
        'city_of_event' => 'city_of_event',
        'reg_type'    => 'regtype',
        'interested'  => 'interested_in',
        'purpose'     => 'purpose',
        'product'     => 'productsolution',
        'regsite'     => 'regsite',
        'created_at'  => 'created_at',
    ];

    /**
     * Columns that may or may not be there.
     *
     * Consent was added to the registration form after this platform was
     * built, and it may not be on every copy of the database. Asking for a
     * column that does not exist fails the whole read, so these are checked
     * first and simply left out when absent — which lands every registration
     * as "no consent recorded", the correct and safe answer.
     */
    private const OPTIONAL_COLUMNS = [
        'optin'    => 'whatsapp_optin',
        'optin_at' => 'whatsapp_optin_at',
    ];

    /** Values that mean "nothing was entered" in their data. */
    private const EMPTY_MARKERS = ['', '-', 'none', 'n/a', 'na', 'null'];

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    public static function isConfigured(): bool
    {
        return self::setting('db_name') !== null
            && self::setting('db_user') !== null;
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $value = Settings::get('regsource.' . $key, $default);
        return ($value === null || trim($value) === '') ? $default : trim($value);
    }

    public static function tableName(): string
    {
        $table = (string)self::setting('table', 'registrations');
        // Only ever an identifier. Nothing from settings reaches SQL unquoted.
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) === 1 ? $table : 'registrations';
    }

    /** Which of their events belong to this exhibition. */
    public static function eventIds(int $agentId): array
    {
        $raw = (string)Settings::get('regsource.events.' . $agentId, '');
        $ids = [];
        foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $part) {
            if ($part !== '') {
                $ids[] = (int)$part;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function setEventIds(int $agentId, string $raw): void
    {
        $ids = [];
        foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $part) {
            if ($part !== '') {
                $ids[] = (int)$part;
            }
        }
        Settings::set('regsource.events.' . $agentId, implode(',', array_unique($ids)));
    }

    /**
     * The last source id already collected for this exhibition.
     *
     * Kept as a setting rather than derived from what was imported, because
     * rows that were read and deliberately skipped must not be read again.
     */
    public static function watermark(int $agentId): int
    {
        return (int)Settings::get('regsource.watermark.' . $agentId, '0');
    }

    public static function setWatermark(int $agentId, int $id): void
    {
        Settings::set('regsource.watermark.' . $agentId, (string)max(0, $id));
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    /**
     * A connection to their database, or null with the reason.
     *
     * @return array{link: ?mysqli, error: ?string}
     */
    public static function connect(): array
    {
        if (!self::isConfigured()) {
            return ['link' => null, 'error' => 'The registration database has not been set up yet.'];
        }

        $host = (string)self::setting('db_host', 'localhost');
        $name = (string)self::setting('db_name', '');
        $user = (string)self::setting('db_user', '');
        $pass = (string)Settings::get('regsource.db_pass', '');

        // The platform's own connection already runs in exception mode, which
        // is what turns a failure here into something catchable rather than a
        // warning and a null link.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $link = new mysqli($host, $user, $pass, $name);
            $link->set_charset('utf8mb4');
            return ['link' => $link, 'error' => null];
        } catch (Throwable $ex) {
            app_log('regsource', 'connection failed', ['error' => $ex->getMessage()]);
            return ['link' => null, 'error' => self::friendlyError($ex->getMessage())];
        }
    }

    /**
     * Check the connection and report what is there, without importing.
     *
     * @return array{ok: bool, message: string, total: ?int, latest_id: ?int, events: array}
     */
    public static function test(): array
    {
        $conn = self::connect();
        if ($conn['link'] === null) {
            return ['ok' => false, 'message' => (string)$conn['error'], 'total' => null, 'latest_id' => null, 'events' => []];
        }

        $link  = $conn['link'];
        $table = self::tableName();

        try {
            $row = $link->query("SELECT COUNT(*) AS c, MAX(`id`) AS m FROM `{$table}`")?->fetch_assoc();
            $total  = (int)($row['c'] ?? 0);
            $latest = (int)($row['m'] ?? 0);

            // A quick look at which events are actually in there, so the
            // operator can map them without going to phpMyAdmin.
            $events = [];
            $res = $link->query(
                "SELECT `event_id`, `city_of_event`, COUNT(*) AS c, MAX(`id`) AS m
                   FROM `{$table}`
                  GROUP BY `event_id`, `city_of_event`
                  ORDER BY c DESC LIMIT 25"
            );
            while ($res && ($e = $res->fetch_assoc())) {
                $events[] = [
                    'event_id'  => (int)$e['event_id'],
                    'city'      => (string)($e['city_of_event'] ?? ''),
                    'count'     => (int)$e['c'],
                    'latest_id' => (int)$e['m'],
                ];
            }

            // Said here because it decides whether anyone can ever be
            // messaged, and the person running the test is the one who can
            // ask for the columns to be added.
            $present = self::presentOptionalColumns($link, $table);
            $consent = isset($present['optin'])
                ? ' The WhatsApp consent column was found, so people who ticked the box can be messaged.'
                : ' The WhatsApp consent column (whatsapp_optin) was not found, so nobody will be messaged '
                  . 'until it is added to the registration table.';

            $link->close();

            return [
                'ok'        => true,
                'message'   => 'Connected. ' . number_format($total) . ' registrations in total.' . $consent,
                'total'     => $total,
                'latest_id' => $latest,
                'events'    => $events,
            ];
        } catch (Throwable $ex) {
            $link->close();
            return [
                'ok' => false,
                'message' => self::friendlyError($ex->getMessage()),
                'total' => null, 'latest_id' => null, 'events' => [],
            ];
        }
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Registrations for this exhibition newer than the watermark.
     *
     * @return array{rows: array, error: ?string, highest_id: int}
     */
    public static function newRows(int $agentId, int $limit = 100): array
    {
        $events = self::eventIds($agentId);
        if (!$events) {
            return ['rows' => [], 'error' => 'No event numbers are mapped to this exhibition yet.', 'highest_id' => 0];
        }

        $conn = self::connect();
        if ($conn['link'] === null) {
            return ['rows' => [], 'error' => (string)$conn['error'], 'highest_id' => 0];
        }

        $link  = $conn['link'];
        $table = self::tableName();
        $since = self::watermark($agentId);
        $limit = max(1, min(500, $limit));

        // Event ids are integers cast from settings, so they cannot carry SQL.
        $eventList = implode(',', array_map('intval', $events));
        $columns = self::COLUMNS + self::presentOptionalColumns($link, $table);
        $cols    = [];
        foreach ($columns as $alias => $column) {
            $cols[] = "`{$column}` AS `{$alias}`";
        }

        $sql = 'SELECT ' . implode(', ', $cols)
             . " FROM `{$table}`"
             . " WHERE `event_id` IN ({$eventList}) AND `id` > ?"
             . ' ORDER BY `id` ASC LIMIT ' . $limit;

        try {
            $stmt = $link->prepare($sql);
            $stmt->bind_param('i', $since);
            $stmt->execute();
            $res = $stmt->get_result();

            $rows    = [];
            $highest = $since;
            while ($row = $res->fetch_assoc()) {
                $rows[]  = self::clean($row);
                $highest = max($highest, (int)$row['id']);
            }

            $stmt->close();
            $link->close();

            return ['rows' => $rows, 'error' => null, 'highest_id' => $highest];
        } catch (Throwable $ex) {
            $link->close();
            app_log('regsource', 'read failed', ['error' => $ex->getMessage()]);
            return ['rows' => [], 'error' => self::friendlyError($ex->getMessage()), 'highest_id' => 0];
        }
    }

    /** How many rows are waiting, without reading them. */
    public static function pendingCount(int $agentId): ?int
    {
        $events = self::eventIds($agentId);
        if (!$events) {
            return null;
        }
        $conn = self::connect();
        if ($conn['link'] === null) {
            return null;
        }

        $link  = $conn['link'];
        $table = self::tableName();
        $since = self::watermark($agentId);
        $list  = implode(',', array_map('intval', $events));

        try {
            $stmt = $link->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `event_id` IN ({$list}) AND `id` > ?");
            $stmt->bind_param('i', $since);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $link->close();
            return (int)($row['c'] ?? 0);
        } catch (Throwable) {
            $link->close();
            return null;
        }
    }

    /**
     * The id to start from when connecting for the first time.
     *
     * Their table holds well over a hundred thousand historic rows. Importing
     * all of it by accident would be slow and would flood the screens with
     * registrations from years ago, so a fresh connection starts at the
     * present and any backfill is something an operator asks for on purpose.
     */
    public static function currentHighestId(int $agentId): ?int
    {
        $events = self::eventIds($agentId);
        if (!$events) {
            return null;
        }
        $conn = self::connect();
        if ($conn['link'] === null) {
            return null;
        }
        $link  = $conn['link'];
        $table = self::tableName();
        $list  = implode(',', array_map('intval', $events));
        try {
            $row = $link->query("SELECT MAX(`id`) AS m FROM `{$table}` WHERE `event_id` IN ({$list})")?->fetch_assoc();
            $link->close();
            return (int)($row['m'] ?? 0);
        } catch (Throwable) {
            $link->close();
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Tidying
    // -----------------------------------------------------------------

    /**
     * Which of the optional columns this table actually has.
     *
     * Asked of the database rather than assumed, and remembered for the life
     * of the request so a batch of 200 rows costs one lookup.
     */
    private static function presentOptionalColumns(mysqli $link, string $table): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $found = [];
        try {
            $res = $link->query("SHOW COLUMNS FROM `{$table}`");
            $have = [];
            while ($res && ($row = $res->fetch_assoc())) {
                $have[strtolower((string)$row['Field'])] = true;
            }
            foreach (self::OPTIONAL_COLUMNS as $alias => $column) {
                if (isset($have[strtolower($column)])) {
                    $found[$alias] = $column;
                }
            }
        } catch (Throwable $ex) {
            app_log('regsource', 'column check failed', ['error' => $ex->getMessage()]);
        }

        return $cache = $found;
    }

    /** Whether the registration form records consent at all yet. */
    public static function hasConsentColumns(): bool
    {
        $conn = self::connect();
        if ($conn['link'] === null) {
            return false;
        }
        $present = self::presentOptionalColumns($conn['link'], self::tableName());
        $conn['link']->close();
        return isset($present['optin']);
    }

    /** Trim, and turn their placeholder values into genuine absence. */
    private static function clean(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $text = trim((string)$value);
            $out[$key] = in_array(strtolower($text), self::EMPTY_MARKERS, true) ? null : $text;
        }
        $out['id'] = (int)($row['id'] ?? 0);
        return $out;
    }

    /**
     * Their dates arrive either as a real datetime or as text, depending on
     * how the column was defined. Both are accepted; neither is guessed at.
     */
    public static function parseDate(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        $stamp = strtotime($value);
        return $stamp === false ? null : gmdate('Y-m-d H:i:s', $stamp);
    }

    /** Database errors, said in a way an operator can act on. */
    private static function friendlyError(string $raw): string
    {
        $lower = strtolower($raw);

        if (str_contains($lower, 'access denied')) {
            return 'The username or password was refused. Check them, and that the user '
                 . 'has been given access to the database in cPanel.';
        }
        if (str_contains($lower, 'unknown database')) {
            return 'That database name does not exist on this server.';
        }
        if (str_contains($lower, "doesn't exist") || str_contains($lower, 'unknown table')) {
            return 'Connected, but that table is not in the database.';
        }
        if (str_contains($lower, 'unknown column')) {
            return 'Connected, but the table does not have the columns expected. '
                 . 'The registration form may have changed.';
        }
        if (str_contains($lower, 'connection refused') || str_contains($lower, "can't connect")) {
            return 'Could not reach the database server.';
        }
        return 'Could not read the registrations: ' . mb_substr($raw, 0, 160);
    }
}
