<?php

namespace Laravel\Ai\Streaming\Events;

class ToolInputDelta extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $toolCallId,
        public string $toolName,
        public string $delta,
        public string $argumentsSoFar,
        public int $timestamp,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_input_delta',
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'delta' => $this->delta,
            'arguments_so_far' => $this->argumentsSoFar,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return null;
    }
}
