<?php

namespace Laravel\Ai\Messages;

class SystemMessage extends Message
{
    public function __construct(string $content)
    {
        parent::__construct('system', $content);
    }
}
