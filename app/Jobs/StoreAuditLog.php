<?php

namespace App\Jobs;

use App\Services\SupabaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StoreAuditLog implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public array $actor,
        public string $action,
        public string $targetType,
        public string|int|null $targetId = null,
        public array $metadata = [],
    ) {}

    public function handle(SupabaseService $supabase): void
    {
        $supabase->storeAudit(
            $this->actor,
            $this->action,
            $this->targetType,
            $this->targetId,
            $this->metadata,
        );
    }
}
