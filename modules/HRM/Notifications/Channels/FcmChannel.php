<?php

namespace Modules\HRM\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Services\PushService;

/**
 * Phase 4 — the push half of a notification.
 *
 * A notification opts in by exposing `toPush($notifiable): ['title','body','data']`
 * next to its `toArray()`; anything without it (mail-only, legacy) passes through
 * untouched. The channel itself never throws — PushService already swallows per-token
 * failures, and this is the outer belt-and-braces so a push can never 500 the request
 * (an approval, a task assignment) that triggered it.
 */
class FcmChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        try {
            if (! method_exists($notification, 'toPush')) {
                return;
            }

            $push = $notification->toPush($notifiable);

            app(PushService::class)->sendToUser(
                $notifiable,
                (string) ($push['title'] ?? ''),
                (string) ($push['body'] ?? ''),
                is_array($push['data'] ?? null) ? $push['data'] : [],
            );
        } catch (\Throwable $e) {
            Log::warning('Push skipped: '.$e->getMessage());
        }
    }
}
