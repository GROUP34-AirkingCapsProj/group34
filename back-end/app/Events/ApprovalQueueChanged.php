<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ApprovalQueueChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $type = 'changed',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('org'),
            new PrivateChannel('location.' . $this->locationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'approval-queue.changed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'location_id' => $this->locationId, 'at' => now()->toIso8601String()];
    }
}
