<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TransferChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $from,
        public int $to,
        public string $type = 'changed',
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('org'), new PrivateChannel('location.' . $this->from)];
        if ($this->to !== $this->from) {
            $channels[] = new PrivateChannel('location.' . $this->to);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'transfer.changed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'from' => $this->from, 'to' => $this->to, 'at' => now()->toIso8601String()];
    }
}
