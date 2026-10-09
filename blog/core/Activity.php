<?php
declare(strict_types=1);

/** Who did what and when — shown in the admin's activity log. */
final class Activity
{
    public static function log(string $action, string $subject = '', array $details = [], ?int $apiKeyId = null): void
    {
        try {
            db()->insert('activity', [
                'user_id'    => Auth::id(),
                'api_key_id' => $apiKeyId,
                'action'     => mb_substr($action, 0, 64),
                'subject'    => mb_substr($subject, 0, 255),
                'details'    => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'ip_hash'    => ip_hash(),
            ]);
        } catch (Throwable $ex) {
            blog_log('activity', 'could not record', ['error' => $ex->getMessage()]);
        }
    }
}
