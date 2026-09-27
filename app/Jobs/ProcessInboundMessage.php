<?php

namespace App\Jobs;

use App\Services\Conversation;
use App\Services\InboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param array<string, mixed> $message constructor arguments of InboundMessage */
    public function __construct(public array $message) {}

    public function handle(Conversation $conversation): void
    {
        $conversation->handle(new InboundMessage(...$this->message));
    }

    public static function fromInbound(InboundMessage $in): self
    {
        return new self([
            'from' => $in->from, 'type' => $in->type, 'text' => $in->text, 'replyId' => $in->replyId,
            'mediaId' => $in->mediaId, 'mime' => $in->mime, 'waId' => $in->waId, 'profileName' => $in->profileName,
        ]);
    }
}
