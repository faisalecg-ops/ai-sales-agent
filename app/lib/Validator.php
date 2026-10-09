<?php
declare(strict_types=1);

/**
 * Input validation. Used for both visitor-supplied data and
 * LLM-extracted lead fields, which are equally untrusted (Section 27, 28).
 */
final class Validator
{
    public static function email(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value) > 255) {
            return null;
        }
        // Reject obvious placeholders the model might echo back.
        $lower = strtolower($value);
        foreach (['example.com', 'email.com', 'test@test', 'user@domain', 'yourcompany.com', 'name@company'] as $bad) {
            if (str_contains($lower, $bad)) {
                return null;
            }
        }
        $clean = filter_var($value, FILTER_VALIDATE_EMAIL);
        return is_string($clean) ? $clean : null;
    }

    /**
     * Normalise a phone number. Keeps a leading + and digits only.
     * Requires 7–15 digits, matching E.164 bounds.
     */
    public static function phone(?string $value): ?string
    {
        $value = (string)$value;
        if ($value === '') {
            return null;
        }
        $plus   = str_starts_with(trim($value), '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $len    = strlen($digits);
        if ($len < 7 || $len > 15) {
            return null;
        }
        if (preg_match('/^(\d)\1+$/', $digits)) {   // 0000000, 1111111
            return null;
        }
        return ($plus ? '+' : '') . $digits;
    }

    public static function url(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $value)) {
            $value = 'https://' . $value;
        }
        if (mb_strlen($value) > 255) {
            return null;
        }
        $clean = filter_var($value, FILTER_VALIDATE_URL);
        if (!is_string($clean)) {
            return null;
        }
        $scheme = strtolower((string)parse_url($clean, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $clean : null;
    }

    /** Plain single-line text field with a length cap. */
    public static function text(?string $value, int $max = 200): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
        $value = str_replace(["\0", "\r", "\n"], '', $value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }

    /** Multi-line text with a length cap. */
    public static function paragraph(?string $value, int $max = 4000): ?string
    {
        $value = str_replace("\0", '', (string)$value);
        $value = preg_replace("/\r\n?/", "\n", $value) ?? '';
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Restrict a value to an allow-list, else return the fallback. */
    public static function inList(?string $value, array $allowed, ?string $fallback = null): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    public static function int(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $n = (int)$value;
        return max($min, min($max, $n));
    }

    public static function decimal(mixed $value, float $min, float $max, ?float $default = null): ?float
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $n = (float)$value;
        if ($n < $min || $n > $max) {
            return $default;
        }
        return $n;
    }

    /** Session / visitor identifiers: opaque, restricted character set. */
    public static function opaqueId(?string $value, int $max = 64): ?string
    {
        $value = (string)$value;
        if (!preg_match('/^[A-Za-z0-9_\-]{8,' . $max . '}$/', $value)) {
            return null;
        }
        return $value;
    }

    public static function slug(?string $value, int $max = 64): ?string
    {
        $value = strtolower(trim((string)$value));
        if (!preg_match('/^[a-z0-9][a-z0-9\-_]{1,' . ($max - 1) . '}$/', $value)) {
            return null;
        }
        return $value;
    }

    public static function date(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }

    /** Hostname for the allowed-domain list. */
    public static function hostname(?string $value): ?string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('~^https?://~', '', $value) ?? '';
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];
        if ($value === '' || mb_strlen($value) > 160) {
            return null;
        }
        if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/', $value)
            && !in_array($value, ['localhost'], true)) {
            return null;
        }
        return $value;
    }
}
