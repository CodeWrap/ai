<?php

namespace Laravel\Ai\Providers\Tools;

use InvalidArgumentException;

class Advisor extends ProviderTool
{
    /**
     * @param  string  $model  Advisor model ID (e.g. "claude-opus-4-6").
     * @param  ?int  $maxUses  Per-request cap on advisor invocations.
     * @param  ?string  $cacheTtl  Anthropic ephemeral cache TTL. Accepts "5m" or "1h".
     */
    public function __construct(
        public string $model,
        public ?int $maxUses = null,
        public ?string $cacheTtl = null,
    ) {
        if ($model === '') {
            throw new InvalidArgumentException('Advisor model must be a non-empty string.');
        }

        if ($maxUses !== null && $maxUses < 1) {
            throw new InvalidArgumentException('Advisor maxUses must be a positive integer.');
        }

        if ($cacheTtl !== null && ! in_array($cacheTtl, ['5m', '1h'], true)) {
            throw new InvalidArgumentException('Advisor cacheTtl must be "5m" or "1h".');
        }
    }
}
