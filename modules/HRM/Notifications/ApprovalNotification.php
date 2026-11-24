<?php

namespace Modules\HRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\HRM\Notifications\Channels\FcmChannel;

/**
 * Approval events: in-app bell (database) + push (FCM, Phase 4).
 *
 * Written when an application lands on an approver's desk, moves a stage, or
 * reaches a final decision — the payload carries everything the bell needs to
 * deep-link: ref_type (leave|overtime) + ref_id. Push carries the same pair in
 * its data so the app routes the tap identically.
 */
class ApprovalNotification extends Notification
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
