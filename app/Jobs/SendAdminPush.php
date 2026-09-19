<?php

namespace App\Jobs;

use App\Services\AdminPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAdminPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $tag,
    ) {}

    public function handle(AdminPushService $push): void
    {
        $push->send($this->title, $this->body, $this->url, $this->tag);
    }
}
