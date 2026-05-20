<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard.spv')];
    }

    public function broadcastAs(): string
    {
        return 'AiConversationUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'AiConversationUpdated',
            'data' => $this->data,
        ];
    }
}

