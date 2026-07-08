<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class BudgetChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $type = 'changed',
        public array $extra = [],
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
        return 'budget.changed';
    }

    public function broadcastWith(): array
    {
        return array_merge(
            ['type' => $this->type, 'location_id' => $this->locationId, 'at' => now()->toIso8601String()],
            $this->extra
        );
    }
}
