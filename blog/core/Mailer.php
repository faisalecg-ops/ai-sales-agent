<?php
declare(strict_types=1);

/**
 * Email for newsletter confirmations, sent from the event's own address.
 * Queued first and sent at once (or by cron if the mail server is slow).
 * Works with the hosting's PHP mail or the event's own SMTP server.
 */
final class Mailer
{
    /** For tests: fn(string $to, string $subject, string $text, ?string $html): bool */
    public static $transport = null;
    private static string $lastError = '';

    public static function queue(string $to, string $subject, string $text, ?string $html = null): int
    {
        return db()->insert('mail_outbox', ['to_email' => $to, 'subject' => mb_substr($subject, 0, 255), 'body_text' => $text, 'body_html' => $html]);
    }

    public static function flush(int $limit = 10): int
    {
        $sent = 0;
        foreach (db()->all("SELECT * FROM {p}mail_outbox WHERE status = 'queued' AND attempts < 5 ORDER BY id LIMIT " . $limit) as $m) {
            $ok = self::send((string)$m['to_email'], (string)$m['subject'], (string)$m['body_text'], $m['body_html']);
            $attempts = (int)$m['attempts'] + 1;
            db()->update('mail_outbox', [
                'status'     => $ok ? 'sent' : ($attempts >= 5 ? 'failed' : 'queued'),
                'attempts'   => $attempts,
                'sent_at'    => $ok ? now_utc() : null,
                'last_error' => $ok ? '' : mb_substr(self::$lastError, 0, 255),
            ], 'id = ?', [(int)$m['id']]);
            $sent += $ok ? 1 : 0;
        }
        return $sent;
    }

    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        if (self::$transport) {
            return (bool)(self::$transport)($to, $subject, $text, $html);
        }
        $fromEmail = trim((string)Settings::get('mail_from_email', '')) ?: (string)Settings::get('contact_email', '');
        if ($fromEmail === '') {
            self::$lastError = 'No sender address set in Settings → Email.';
            return false;
        }
        $fromName = trim((string)Settings::get('mail_from_name', '')) ?: Event::name();
        [$headers, $body] = self::compose($text, $html, $fromEmail, $fromName);
        if (Settings::get('mail_transport', 'mail') === 'smtp') {
            return self::smtp($to, $subject, $headers, $body, $fromEmail);
        }
        $ok = @mail($to, self::encode($subject), $body, $headers, '-f' . $fromEmail);
        if (!$ok) {
            self::$lastError = 'The hosting mail function refused the message.';
        }
        return $ok;
    }

    private static function encode(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function compose(string $text, ?string $html, string $fromEmail, string $fromName): array
    {
        $boundary = 'b' . bin2hex(random_bytes(8));
        $h = ['From: ' . self::encode($fromName) . ' <' . $fromEmail . '>', 'MIME-Version: 1.0',
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (substr(strrchr($fromEmail, '@') ?: '@localhost', 1)) . '>'];
        if ($html === null) {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: base64';
            return [implode("\r\n", $h), chunk_split(base64_encode($text))];
        }
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
            . '--' . $boundary . "--\r\n";
        return [implode("\r\n", $h), $body];
    }

    private static function smtp(string $to, string $subject, string $headers, string $body, string $fromEmail): bool
    {
        $host = (string)Settings::get('smtp_host', '');
        $port = (int)Settings::get('smtp_port', 587);
        $enc = (string)Settings::get('smtp_encryption', 'tls');
        $user = (string)Settings::get('smtp_username', '');
        $pass = Secret::decrypt((string)Settings::get('smtp_password', ''));
        if ($host === '') {
            self::$lastError = 'SMTP server not set.';
            return false;
        }
        $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port, $errno, $errstr, 15);
        if (!$fp) {
            self::$lastError = 'Cannot connect to the mail server: ' . $errstr;
            return false;
        }
        stream_set_timeout($fp, 15);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $l) use ($fp, $read): int {
            fwrite($fp, $l . "\r\n");
            return (int)substr($read(), 0, 3);
        };
        try {
            if ((int)substr($read(), 0, 3) !== 220) {
                throw new RuntimeException('no greeting');
            }
            $me = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
            $cmd('EHLO ' . $me);
            if ($enc === 'tls') {
                if ($cmd('STARTTLS') !== 220 || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS failed');
                }
                $cmd('EHLO ' . $me);
            }
            if ($user !== '' && ($cmd('AUTH LOGIN') !== 334 || $cmd(base64_encode($user)) !== 334 || $cmd(base64_encode($pass)) !== 235)) {
                throw new RuntimeException('login refused');
            }
            if ($cmd('MAIL FROM:<' . $fromEmail . '>') !== 250 || !in_array($cmd('RCPT TO:<' . $to . '>'), [250, 251], true) || $cmd('DATA') !== 354) {
                throw new RuntimeException('message refused');
            }
            $msg = 'To: <' . $to . ">\r\nSubject: " . self::encode($subject) . "\r\nDate: " . gmdate('r') . "\r\n" . $headers . "\r\n\r\n"
                . preg_replace('/^\./m', '..', $body) . "\r\n.";
            if ($cmd($msg) !== 250) {
                throw new RuntimeException('not accepted');
            }
            $cmd('QUIT');
            fclose($fp);
            return true;
        } catch (Throwable $ex) {
            self::$lastError = 'Mail server: ' . $ex->getMessage();
            fclose($fp);
            return false;
        }
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }
}
