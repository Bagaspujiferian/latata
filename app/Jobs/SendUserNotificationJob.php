<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendUserNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $userId,
        public string $category,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $refType = null,
        public ?int $refId = null,
    ) {
        $this->afterCommit();
    }

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->notify(
            userId: $this->userId,
            category: $this->category,
            title: $this->title,
            body: $this->body,
            actionUrl: $this->actionUrl,
            refType: $this->refType,
            refId: $this->refId,
        );
    }
}
