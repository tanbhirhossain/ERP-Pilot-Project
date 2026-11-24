<?php

namespace Modules\HRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\HRM\Notifications\Channels\FcmChannel;

/**
 * Generic desk events: in-app bell (database) + push (FCM, Phase 4).
 *
 * ref_type carries the domain (task|notice|roster|...), ref_id the row — the
 * bell deep-links off the pair. ApprovalNotification stays approval-shaped;
 * this one is deliberately unopinionated for the team domains.
 */
class DeskNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $refType,
        private readonly int $refId,
        private readonly array $extra = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => array_merge($this->toArray($notifiable), ['ref_id' => (string) $this->refId]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'title' => $this->title,
            'body' => $this->body,
            'ref_type' => $this->refType,
            'ref_id' => $this->refId,
        ], $this->extra);
    }
}
