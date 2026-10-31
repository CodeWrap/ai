<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ImageReturningTool implements Tool
{
    public function description(): string
    {
        return 'Returns an image as content blocks.';
    }

    public function handle(Request $request): string|array
    {
        return [
            ['type' => 'image', 'source' => [
                'type' => 'base64',
                'media_type' => 'image/jpeg',
                'data' => base64_encode('fake-jpeg-bytes'),
            ]],
            ['type' => 'text', 'text' => 'Screenshot of the page'],
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
