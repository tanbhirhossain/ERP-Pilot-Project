<?php

namespace Modules\CORE\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Generic database-channel notification rendered by the bell inbox.
 *
 * `url` is a route *path* (e.g. /purchase/bills/12), resolved server-side by the
 * emitter, so the front end never needs Ziggy to follow it.
 */
class SystemNotification extends Notification
{
    use Queueable;

    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $title,
        public readonly string $body = '',
        public readonly ?string $url = null,
        public readonly string $level = 'info', // info | success | warning | danger
        public readonly array $data = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'level' => $this->level,
            'data' => $this->data,
        ];
    }
}
