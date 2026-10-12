<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * HTTPS calls to the social platforms (Meta, LinkedIn, X). Kept apart from the
 * sales agent's Http helper because these need response headers, binary
 * uploads and multipart forms. The tests replace the network with $hook.
 */
final class SmNet
{
    /** Set only by the test suite: fn(string $method, string $url, array $headers, ?string $body): array{status,body,headers} */
    public static ?Closure $hook = null;

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,string>, json:?array, error:string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
    {
        if (self::$hook !== null) {
            $r = (self::$hook)(strtoupper($method), $url, $headers, $body);
            $r += ['status' => 0, 'body' => '', 'headers' => [], 'error' => ''];
            $r['headers'] = array_change_key_case((array)$r['headers'], CASE_LOWER);
            $r['json'] = json_decode((string)$r['body'], true);
            return $r;
        }
        if (!preg_match('#^https://#i', $url)) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'json' => null, 'error' => 'Only secure (https) addresses are used.'];
        }
        $respHeaders = [];
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $h, CURLOPT_USERAGENT => 'EventSocialAgent/1.0',
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);
        $text = is_string($out) ? $out : '';
        return ['status' => $status, 'body' => $text, 'headers' => $respHeaders, 'json' => json_decode($text, true), 'error' => $err];
    }

    /**
     * True when the address is on the public internet (not this server or a private
     * network). Used before fetching addresses that come from outside websites.
     */
    public static function publicUrl(string $url): bool
    {
        if (!preg_match('#^https://#i', $url)) {
            return false;
        }
        if (self::$hook !== null) {
            return true;   // tests: no real network
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    /** Form-encoded POST. */
    public static function form(string $url, array $fields, array $headers = []): array
    {
        return self::request('POST', $url, $headers + ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    public static function json(string $method, string $url, array $data, array $headers = []): array
    {
        return self::request($method, $url, $headers + ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * multipart/form-data with plain fields and files given as bytes.
     * @param array<string,string> $fields
     * @param array<string,array{name:string,type:string,bytes:string}> $files
     */
    public static function multipart(string $url, array $fields, array $files, array $headers = []): array
    {
        $b = 'smb' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        foreach ($files as $k => $f) {
            $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"" . addslashes($f['name']) . "\"\r\nContent-Type: {$f['type']}\r\n\r\n{$f['bytes']}\r\n";
        }
        $body .= "--$b--\r\n";
        return self::request('POST', $url, $headers + ['Content-Type' => 'multipart/form-data; boundary=' . $b], $body);
    }

    /** A short, plain reason from a platform's error answer. */
    public static function reason(array $r): string
    {
        $j = $r['json'] ?? null;
        $msg = '';
        if (is_array($j)) {
            $msg = (string)($j['error']['message'] ?? $j['message'] ?? $j['detail'] ?? $j['error_description'] ?? ($j['errors'][0]['message'] ?? $j['errors'][0]['detail'] ?? '') ?? '');
            if ($msg === '' && is_string($j['error'] ?? null)) {
                $msg = (string)$j['error'];
            }
        }
        if ($r['status'] === 0) {
            return 'The platform could not be reached' . ($r['error'] !== '' ? ' (' . mb_substr($r['error'], 0, 120) . ')' : '') . '.';
        }
        return trim('Error ' . $r['status'] . ($msg !== '' ? ': ' . mb_substr(strip_tags($msg), 0, 300) : ''));
    }

    /** Worth trying again later (network trouble, busy or failing server). */
    public static function transient(array $r): bool
    {
        return $r['status'] === 0 || $r['status'] === 429 || $r['status'] >= 500;
    }
}
