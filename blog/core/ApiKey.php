<?php
declare(strict_types=1);

/**
 * API keys for the Social Media Agent. The full key is shown once when it
 * is created; only a scrambled (hashed) copy is stored, so a database leak
 * does not leak working keys.
 */
final class ApiKey
{
    /** @return array{id:int, key:string} */
    public static function create(string $name, string $permission): array
    {
        $permission = $permission === 'publish' ? 'publish' : 'drafts';
        $key = 'eb_' . bin2hex(random_bytes(24));
        $id = db()->insert('api_keys', [
            'name'       => mb_substr(trim($name) ?: 'API key', 0, 120),
            'key_prefix' => substr($key, 0, 12),
            'key_hash'   => self::hash($key),
            'permission' => $permission,
            'created_by' => Auth::id(),
        ]);
        Activity::log('api_key.created', $name, ['id' => $id, 'permission' => $permission]);
        return ['id' => $id, 'key' => $key];
    }

    public static function hash(string $key): string
    {
        return hash_hmac('sha256', $key, 'apikey|' . app_key());
    }

    public static function verify(string $key): ?array
    {
        if (!preg_match('/^eb_[a-f0-9]{48}$/', $key)) {
            return null;
        }
        $row = db()->one('SELECT * FROM {p}api_keys WHERE key_hash = ? AND active = 1', [self::hash($key)]);
        if ($row) {
            db()->update('api_keys', ['last_used_at' => now_utc()], 'id = ?', [(int)$row['id']]);
        }
        return $row;
    }
}
