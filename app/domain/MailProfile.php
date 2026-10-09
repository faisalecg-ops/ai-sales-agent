<?php
declare(strict_types=1);

/**
 * Each exhibition's own email identity: the name and address its emails
 * come from, and the mail server they go through.
 *
 * Build Asia writes as "Build Asia Secretariat" <info@buildasia.net> through
 * Build Asia's server; Pharma Asia as its own secretariat through its own.
 * One exhibition's mail never goes out under another's name, and there is no
 * fallback to the platform's own address: an exhibition without a complete
 * profile simply cannot send prospect emails.
 *
 * The platform's own address (Settings) is still used for the team's
 * internal notifications, which are not written to anyone outside.
 */
final class MailProfile
{
    public const ENCRYPTIONS = [
        'tls'  => 'STARTTLS (usually port 587)',
        'ssl'  => 'SSL (usually port 465)',
        'none' => 'None (not recommended)',
    ];

    /** The profile, ready for Mailer::sendWith(). */
    public static function forAgent(array $agent): array
    {
        $stored = trim((string)($agent['mail_smtp_password'] ?? ''));
        return [
            'from_name'  => trim((string)($agent['mail_from_name'] ?? '')) ?: (string)($agent['name'] ?? ''),
            'from_email' => trim((string)($agent['mail_from_email'] ?? '')),
            'reply_to'   => trim((string)($agent['mail_reply_to'] ?? '')) ?: null,
            'host'       => trim((string)($agent['mail_smtp_host'] ?? '')),
            'port'       => (int)($agent['mail_smtp_port'] ?? 587) ?: 587,
            'encryption' => array_key_exists((string)($agent['mail_smtp_encryption'] ?? ''), self::ENCRYPTIONS)
                ? (string)$agent['mail_smtp_encryption'] : 'tls',
            'username'   => trim((string)($agent['mail_smtp_username'] ?? '')),
            'password'   => $stored === '' ? '' : (string)(Crypto::decrypt($stored) ?? ''),
        ];
    }

    /** Why this exhibition cannot send prospect emails yet, or null. */
    public static function problem(array $agent): ?string
    {
        if (!array_key_exists('mail_from_email', $agent)) {
            return 'The database upgrade for email sending has not been run yet.';
        }
        $p = self::forAgent($agent);
        if (Validator::email($p['from_email']) === null) {
            return 'No "From" email address is set for this exhibition.';
        }
        if ($p['host'] === '') {
            return 'No mail server is set for this exhibition.';
        }
        if ($p['username'] !== '' && $p['password'] === '') {
            return 'The mail server password is missing.';
        }
        if ($p['encryption'] === 'none' && $p['password'] !== ''
            && !in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '::1'], true)) {
            return 'Encryption is set to none, which would send the mail server password unprotected. '
                 . 'Choose STARTTLS (port 587) or SSL (port 465).';
        }
        return null;
    }

    /** Store the mail server password, encrypted. Empty removes it. */
    public static function savePassword(int $agentId, string $password): void
    {
        db()->update('agents', [
            'mail_smtp_password' => $password === '' ? null : Crypto::encrypt($password),
        ], 'id = ?', [$agentId]);
    }

    /**
     * Check what was typed on the settings page.
     *
     * @return list<string> problems, in plain words
     */
    public static function check(array $input): array
    {
        $errors = [];
        $from = trim((string)($input['mail_from_email'] ?? ''));
        if ($from !== '' && Validator::email($from) === null) {
            $errors[] = 'The "From" email address is not a valid address.';
        }
        $reply = trim((string)($input['mail_reply_to'] ?? ''));
        if ($reply !== '' && Validator::email($reply) === null) {
            $errors[] = 'The reply-to address is not a valid address.';
        }
        $host = trim((string)($input['mail_smtp_host'] ?? ''));
        if ($host !== '' && !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
            $errors[] = 'The mail server should be a name such as mail.buildasia.net — without https:// or spaces.';
        }
        $port = (int)($input['mail_smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            $errors[] = 'The mail server port should be a number such as 587 or 465.';
        }
        return $errors;
    }

    /** A plain summary for screens: who the emails come from. */
    public static function sender(array $agent): string
    {
        $p = self::forAgent($agent);
        return $p['from_email'] === '' ? '(not set)' : $p['from_name'] . ' <' . $p['from_email'] . '>';
    }
}
