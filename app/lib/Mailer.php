<?php
declare(strict_types=1);

/**
 * Outbound email. Queued to the database first so a slow or failing mail
 * server never blocks a visitor's chat response, then flushed inline or by
 * a cPanel cron job (Section 46 — no daemon required).
 */
final class Mailer
{
    /**
     * Queue a message. A dedupe key prevents duplicate notifications when a
     * request is retried (Section 32).
     */
    public static function queue(
        string $to,
        string $subject,
        string $body,
        ?int $agentId = null,
        ?string $dedupeKey = null,
        ?string $bodyHtml = null,
        ?array $attachment = null
    ): bool {
        $to = trim($to);
        if (Validator::email($to) === null) {
            return false;
        }
        try {
            db()->insert('email_outbox', [
                'agent_id'   => $agentId,
                'to_email'   => $to,
                'subject'    => mb_substr($subject, 0, 255),
                'body_html' => $bodyHtml,
                'body'       => $body,
                'dedupe_key' => $dedupeKey !== null ? mb_substr($dedupeKey, 0, 96) : null,
                'status'     => 'queued',
            ] + ($attachment !== null && !empty($attachment['path']) ? [
                // A file under the storage folder, sent with the message (a quotation's PDF).
                'attachment_path' => mb_substr((string)$attachment['path'], 0, 255),
                'attachment_name' => mb_substr((string)($attachment['name'] ?? basename((string)$attachment['path'])), 0, 120),
            ] : []));
            return true;
        } catch (Throwable $ex) {
            // Duplicate dedupe_key means the notification is already queued — not an error.
            if (str_contains($ex->getMessage(), 'Duplicate')) {
                return true;
            }
            app_log('mail', 'queue failed', ['error' => $ex->getMessage()]);
            return false;
        }
    }

    /**
     * Send queued messages. Called opportunistically after a request
     * completes, and by the cron script.
     */
    public static function flush(int $limit = 10): int
    {
        $limit = max(1, min(100, $limit));
        $rows  = db()->all(
            "SELECT * FROM email_outbox WHERE status = 'queued' AND attempts < 4
             ORDER BY id ASC LIMIT " . $limit
        );
        $sent = 0;
        foreach ($rows as $row) {
            $ok = self::deliver(
                (string)$row['to_email'],
                (string)$row['subject'],
                (string)$row['body'],
                $row['body_html'] !== null && $row['body_html'] !== '' ? (string)$row['body_html'] : null,
                self::storedAttachment($row)
            );
            if ($ok) {
                db()->update('email_outbox', [
                    'status'  => 'sent',
                    'sent_at' => gmdate('Y-m-d H:i:s'),
                ], 'id = ?', [(int)$row['id']]);
                $sent++;
            } else {
                $attempts = (int)$row['attempts'] + 1;
                db()->update('email_outbox', [
                    'attempts'   => $attempts,
                    'status'     => $attempts >= 4 ? 'failed' : 'queued',
                    'last_error' => mb_substr(self::$lastError ?? 'unknown', 0, 400),
                ], 'id = ?', [(int)$row['id']]);
            }
        }
        return $sent;
    }

    /** The file queued with a message, read from the storage folder. A missing file never stops the message. */
    private static function storedAttachment(array $row): array
    {
        $rel = (string)($row['attachment_path'] ?? '');
        if ($rel === '' || str_contains($rel, '..')) {
            return [];
        }
        $file = STORAGE_PATH . '/' . ltrim($rel, '/');
        if (!is_file($file) || filesize($file) > 8 * 1024 * 1024) {
            return [];
        }
        $name = (string)($row['attachment_name'] ?? '') ?: basename($file);
        return [['name' => $name, 'type' => str_ends_with(strtolower($name), '.pdf') ? 'application/pdf' : 'application/octet-stream',
                 'data' => (string)file_get_contents($file)]];
    }

    private static ?string $lastError = null;

    /** Set when the last failure happened after the message itself was handed over. */
    private static bool $lastUncertain = false;

    /**
     * Set only by the automated tests: receives every message sent with
     * sendWith() instead of a mail server, and returns what the server would.
     *
     * @var null|callable(array $profile, string $to, string $subject, string $body, ?string $html, array $headers): array
     */
    public static $testTransport = null;

    /**
     * Send one message through a given sending profile — an exhibition's own
     * name, address and mail server — rather than the platform's.
     *
     * @param array{from_name:string,from_email:string,reply_to:?string,host:string,port:int,
     *              encryption:string,username:string,password:string} $profile
     * @param array<string,string> $headers extra headers, e.g. List-Unsubscribe
     * @param array<int,array{name:string,type:string,data:string}> $attachments files sent with the message
     * @return array{ok: bool, error: ?string, message_id: string, permanent: bool}
     */
    public static function sendWith(array $profile, string $to, string $subject, string $body,
                                    ?string $bodyHtml = null, array $headers = [], array $attachments = []): array
    {
        $domain = substr(strrchr((string)$profile['from_email'], '@') ?: '@localhost', 1);
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $headers = ['Message-ID' => $messageId] + $headers;

        if (self::$testTransport !== null) {
            $result = (self::$testTransport)($profile, $to, $subject, $body, $bodyHtml, $headers, $attachments);
            return ($result + ['ok' => false, 'error' => null, 'permanent' => false, 'uncertain' => false]) + ['message_id' => $messageId];
        }

        self::$lastError = null;
        self::$lastUncertain = false;
        $ok = self::sendSmtp($to, $subject, $body, $bodyHtml, $profile, $headers, $attachments);
        $error = $ok ? null : (string)self::$lastError;
        return [
            'ok'         => $ok,
            'error'      => $error,
            'message_id' => $messageId,
            // The whole message was written to the server before it failed:
            // it may well have been delivered, and must not be sent again.
            'uncertain'  => !$ok && self::$lastUncertain,
            // Refused by the receiving server for this address: trying again
            // will not help, and should not be done.
            'permanent'  => !$ok && preg_match('/^smtp_rcpt_to 5\d\d/', (string)$error) === 1,
        ];
    }

    /** Deliver one message using the configured transport. */
    public static function deliver(string $to, string $subject, string $body, ?string $bodyHtml = null,
                                   array $attachments = []): bool
    {
        $transport = Settings::get('mail.transport', (string)cfg('mail_transport', 'mail'));
        return $transport === 'smtp'
            ? self::sendSmtp($to, $subject, $body, $bodyHtml, null, [], $attachments)
            : self::sendMail($to, $subject, $body, $bodyHtml, $attachments);
    }

    /**
     * A multipart message carrying both the plain text and the formatted
     * version, so a mail client that cannot render HTML still shows the
     * full briefing rather than a blank message.
     *
     * @return array{headers:string,body:string}
     */
    private static function compose(string $body, ?string $bodyHtml, string $fromAddr, string $fromName,
                                    ?string $replyTo = null, array $extra = [], array $attachments = []): array
    {
        if ($attachments) {
            // The message as usual, wrapped together with its files.
            $inner = self::compose($body, $bodyHtml, $fromAddr, $fromName, $replyTo, $extra);
            $head = [];
            $innerType = [];
            foreach (explode("\r\n", $inner['headers']) as $h) {
                if (stripos($h, 'Content-Type:') === 0 || stripos($h, 'Content-Transfer-Encoding:') === 0) {
                    $innerType[] = $h;
                } else {
                    $head[] = $h;
                }
            }
            $boundary = 'aisa-mix-' . bin2hex(random_bytes(12));
            $head[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $parts = ['--' . $boundary, implode("\r\n", $innerType), '', $inner['body'], ''];
            foreach ($attachments as $file) {
                $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($file['name'] ?? 'attachment')) ?: 'attachment';
                $type = preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', (string)($file['type'] ?? '')) ? (string)$file['type'] : 'application/octet-stream';
                $parts[] = '--' . $boundary;
                $parts[] = 'Content-Type: ' . $type . '; name="' . $name . '"';
                $parts[] = 'Content-Transfer-Encoding: base64';
                $parts[] = 'Content-Disposition: attachment; filename="' . $name . '"';
                $parts[] = '';
                $parts[] = rtrim(chunk_split(base64_encode((string)($file['data'] ?? '')), 76, "\r\n"));
                $parts[] = '';
            }
            $parts[] = '--' . $boundary . '--';
            $parts[] = '';
            return ['headers' => implode("\r\n", $head), 'body' => implode("\r\n", $parts)];
        }

        $common = [
            'From: ' . self::encodeName($fromName) . ' <' . $fromAddr . '>',
            'Reply-To: ' . ($replyTo !== null && $replyTo !== '' ? $replyTo : $fromAddr),
            'MIME-Version: 1.0',
        ];
        foreach ($extra as $name => $value) {
            $name  = preg_replace('/[^A-Za-z0-9-]/', '', (string)$name) ?? '';
            $value = str_replace(["\r", "\n"], ' ', (string)$value);
            if ($name !== '') {
                $common[] = $name . ': ' . $value;
            }
        }

        if ($bodyHtml === null || trim($bodyHtml) === '') {
            $common[] = 'Content-Type: text/plain; charset=UTF-8';
            $common[] = 'Content-Transfer-Encoding: 8bit';
            $common[] = 'X-Mailer: AI Sales Agent Platform';
            return ['headers' => implode("\r\n", $common), 'body' => self::normaliseBody($body)];
        }

        $boundary = 'aisa-' . bin2hex(random_bytes(12));
        $common[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $common[] = 'X-Mailer: AI Sales Agent Platform';

        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            self::normaliseBody($body),
            '',
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            self::normaliseBody($bodyHtml),
            '',
            '--' . $boundary . '--',
            '',
        ];

        return ['headers' => implode("\r\n", $common), 'body' => implode("\r\n", $parts)];
    }

    private static function fromAddress(): array
    {
        return [
            Settings::get('mail.from_address', (string)cfg('mail_from', 'no-reply@localhost')),
            Settings::get('mail.from_name', (string)cfg('mail_from_name', 'AI Sales Agent')),
        ];
    }

    private static function sendMail(string $to, string $subject, string $body, ?string $bodyHtml = null,
                                     array $attachments = []): bool
    {
        [$fromAddr, $fromName] = self::fromAddress();
        $message = self::compose($body, $bodyHtml, (string)$fromAddr, (string)$fromName, null, [], $attachments);
        $ok = @mail($to, self::encodeSubject($subject), $message['body'], $message['headers']);
        if (!$ok) {
            self::$lastError = 'php_mail_returned_false';
        }
        return (bool)$ok;
    }

    /**
     * Minimal SMTP client (AUTH LOGIN over TLS). No external library.
     */
    private static function sendSmtp(string $to, string $subject, string $body, ?string $bodyHtml = null,
                                     ?array $profile = null, array $extraHeaders = [], array $attachments = []): bool
    {
        if ($profile !== null) {
            $host     = (string)$profile['host'];
            $port     = (int)$profile['port'];
            $user     = (string)$profile['username'];
            $pass     = (string)$profile['password'];
            $secure   = (string)$profile['encryption'];
            $fromAddr = (string)$profile['from_email'];
            $fromName = (string)$profile['from_name'];
            $replyTo  = (string)($profile['reply_to'] ?? '');
        } else {
            $host   = (string)Settings::get('mail.smtp.host', '');
            $port   = (int)Settings::get('mail.smtp.port', '587');
            $user   = (string)Settings::get('mail.smtp.username', '');
            $pass   = (string)Settings::get('mail.smtp.password', '');
            $secure = (string)Settings::get('mail.smtp.encryption', 'tls');   // tls | ssl | none
            [$fromAddr, $fromName] = self::fromAddress();
            $replyTo = null;
        }

        if ($host === '') {
            self::$lastError = 'smtp_not_configured';
            return false;
        }

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp  = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            self::$lastError = 'smtp_connect_failed: ' . $errstr;
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
        $cmd = static function (string $line) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            return $read();
        };
        $code = static fn(string $r): int => (int)substr(trim($r), 0, 3);

        $ok = true;
        try {
            if ($code($read()) !== 220) {
                throw new RuntimeException('greeting');
            }
            // Named after the sender's own domain when there is one, which is
            // what receiving servers expect from an exhibition's own mail.
            $senderDomain = substr(strrchr((string)$fromAddr, '@') ?: '', 1);
            $hostname = $profile !== null && $senderDomain !== '' ? $senderDomain : (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
            $cmd('EHLO ' . $hostname);

            if ($secure === 'tls') {
                if ($code($cmd('STARTTLS')) !== 220) {
                    throw new RuntimeException('starttls');
                }
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('tls_handshake');
                }
                $cmd('EHLO ' . $hostname);
            }

            if ($user !== '') {
                if ($code($cmd('AUTH LOGIN')) !== 334) {
                    throw new RuntimeException('auth_start');
                }
                if ($code($cmd(base64_encode($user))) !== 334) {
                    throw new RuntimeException('auth_user');
                }
                if ($code($cmd(base64_encode($pass))) !== 235) {
                    throw new RuntimeException('auth_pass');
                }
            }

            if ($code($cmd('MAIL FROM:<' . $fromAddr . '>')) !== 250) {
                throw new RuntimeException('mail_from');
            }
            $rcpt = $cmd('RCPT TO:<' . $to . '>');
            if (!in_array($code($rcpt), [250, 251], true)) {
                // The server's own words, which say whether the address
                // does not exist (5xx) or the server is busy (4xx).
                throw new RuntimeException('rcpt_to ' . mb_substr(trim($rcpt), 0, 200));
            }
            if ($code($cmd('DATA')) !== 354) {
                throw new RuntimeException('data');
            }

            $composed = self::compose($body, $bodyHtml, (string)$fromAddr, (string)$fromName, $replyTo, $extraHeaders, $attachments);
            $message  = implode("\r\n", [
                'To: <' . $to . '>',
                'Subject: ' . self::encodeSubject($subject),
                'Date: ' . gmdate('r'),
                $composed['headers'],
                '',
                self::dotStuff($composed['body']),
                '.',
            ]);
            // From here on the server has the message. Whatever goes wrong now,
            // it may have been delivered.
            self::$lastUncertain = true;
            $accepted = $cmd($message);
            if ($code($accepted) !== 250) {
                throw new RuntimeException('send ' . mb_substr(trim($accepted), 0, 200));
            }
            $cmd('QUIT');
        } catch (Throwable $ex) {
            self::$lastError = 'smtp_' . $ex->getMessage();
            $ok = false;
        }

        fclose($fp);
        return $ok;
    }

    private static function normaliseBody(string $body): string
    {
        $body = preg_replace("/\r\n?/", "\n", $body) ?? $body;
        return str_replace("\n", "\r\n", $body);
    }

    /** Escape leading dots so they are not read as end-of-message. */
    private static function dotStuff(string $body): string
    {
        return preg_replace("/^\./m", '..', $body) ?? $body;
    }

    private static function encodeSubject(string $subject): string
    {
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        return preg_match('/[\x80-\xFF]/', $subject)
            ? '=?UTF-8?B?' . base64_encode($subject) . '?='
            : $subject;
    }

    private static function encodeName(string $name): string
    {
        $name = str_replace(["\r", "\n", '<', '>'], '', $name);
        return preg_match('/[\x80-\xFF]/', $name)
            ? '=?UTF-8?B?' . base64_encode($name) . '?='
            : '"' . $name . '"';
    }

    /** The reason the last delivery attempt failed, for the settings page. */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }
}
