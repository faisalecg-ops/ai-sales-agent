<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/** Who did what, when — /social's own log (the sales agent's audit log is never written). */
final class SmActivity
{
    public static function log(?int $agentId, string $action, string $subject = '', ?array $user = null): void
    {
        $user ??= function_exists('sm_user') ? sm_user() : null;
        try {
            db()->insert('sm_activity', [
                'agent_id' => $agentId,
                'user_id'  => $user ? (int)$user['id'] : null,
                'who'      => $user ? (string)($user['full_name'] ?: $user['username']) : 'system',
                'action'   => mb_substr($action, 0, 64),
                'subject'  => mb_substr($subject, 0, 255),
            ]);
        } catch (Throwable $e) {
            app_log('social', 'activity log failed', ['error' => $e->getMessage()]);
        }
    }
}
