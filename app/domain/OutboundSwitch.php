<?php
declare(strict_types=1);

/**
 * The emergency stop for everything the platform sends to customers.
 *
 * One switch, for every exhibition and every channel. WhatsApp checks it
 * today; email campaigns will check it when they exist. It is deliberately
 * not per exhibition — the moment somebody needs it is the moment nobody
 * should have to work out which exhibition was at fault.
 *
 * What stops: first messages, follow-ups, automatic replies, and replies
 * typed by the team from the admin screens.
 *
 * What carries on: incoming messages are still recorded, requests to stop
 * are still obeyed, and internal notification emails to the team still go,
 * because those are how the team finds out what is happening.
 */
final class OutboundSwitch
{
    private const KEY_STOPPED = 'outbound.stopped';
    private const KEY_AT      = 'outbound.stopped_at';
    private const KEY_BY      = 'outbound.stopped_by';
    private const KEY_NOTE    = 'outbound.stopped_note';

    public static function isStopped(): bool
    {
        try {
            return (string)Settings::get(self::KEY_STOPPED, '') === '1';
        } catch (Throwable) {
            // If the setting cannot be read, the safe answer is "stopped".
            return true;
        }
    }

    public static function stop(string $actor, string $note = ''): void
    {
        Settings::set(self::KEY_STOPPED, '1');
        Settings::set(self::KEY_AT, gmdate('Y-m-d H:i:s'));
        Settings::set(self::KEY_BY, mb_substr($actor, 0, 64));
        Settings::set(self::KEY_NOTE, mb_substr(trim($note), 0, 255));

        Audit::log('outbound_stopped', null, 'setting', null,
            'All outgoing messages stopped by ' . $actor . (trim($note) !== '' ? ': ' . trim($note) : ''));
    }

    public static function resume(string $actor): void
    {
        Settings::set(self::KEY_STOPPED, '');
        Audit::log('outbound_resumed', null, 'setting', null, 'Outgoing messages resumed by ' . $actor);
    }

    /** @return array{stopped: bool, at: ?string, by: ?string, note: ?string} */
    public static function status(): array
    {
        $stopped = self::isStopped();
        return [
            'stopped' => $stopped,
            'at'      => $stopped ? Settings::get(self::KEY_AT) : null,
            'by'      => $stopped ? Settings::get(self::KEY_BY) : null,
            'note'    => $stopped ? Settings::get(self::KEY_NOTE) : null,
        ];
    }

    public const REASON = 'All outgoing messages are stopped. Resume them from the red banner at the top of the admin panel.';
}
