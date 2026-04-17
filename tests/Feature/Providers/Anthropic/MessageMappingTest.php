<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

test('user message maps to anthropic format', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'];
        $userMessage = $messages[0];

        return $userMessage['role'] === 'user'
            && $userMessage['content'][0]['type'] === 'text'
            && $userMessage['content'][0]['text'] === 'What is Laravel?';
    });
});

test('tool result follow up maps assistant and tool result messages', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpMessages = $recorded[1][0]->data()['messages'];

    $assistantMsg = null;
    $toolResultMsg = null;

    foreach ($followUpMessages as $msg) {
        if ($msg['role'] === 'assistant') {
            foreach ($msg['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $assistantMsg = $msg;
                }
            }
        }

        if ($msg['role'] === 'user') {
            foreach ($msg['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $toolResultMsg = $msg;
                }
            }
        }
    }

    expect($assistantMsg)->not->toBeNull('Follow-up should include assistant message')
        ->and($toolResultMsg)->not->toBeNull('Follow-up should include tool result message');

    $toolUseBlock = collect($assistantMsg['content'])->firstWhere('type', 'tool_use');
    expect($toolUseBlock['name'])->toBe('FixedNumberGenerator')
        ->and($toolUseBlock)->toHaveKey('input');

    $toolResultBlock = collect($toolResultMsg['content'])->firstWhere('type', 'tool_result');
    expect($toolResultBlock['tool_use_id'])->toBe($toolUseBlock['id'])
        ->and($toolResultBlock['content'])->not->toBeEmpty();
});

test('base64 pdf document maps to document content block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $content = $request->data()['messages'][0]['content'];
        $docBlock = $content[0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'base64'
            && $docBlock['source']['media_type'] === 'application/pdf'
            && $docBlock['source']['data'] === base64_encode('fake-pdf-content');
    });
});

test('uploaded pdf file maps to document content block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this file?',
        attachments: [$file],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $content = $request->data()['messages'][0]['content'];
        $docBlock = $content[0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'base64'
            && $docBlock['source']['media_type'] === 'application/pdf';
    });
});

test('assistant message with contentBlocks is replayed verbatim preserving order', function () {
    // Provider-specific blocks like server_tool_use, advisor_tool_result,
    // and thinking would be dropped by the default text+tool_calls rebuild.
    // When contentBlocks is populated, mapAssistantMessage must use it
    // verbatim so Anthropic's follow-up request sees the full ordered
    // history (required by the API for multi-turn conversations).
    $contentBlocks = [
        ['type' => 'text', 'text' => 'Let me consult the advisor.'],
        [
            'type' => 'server_tool_use',
            'id' => 'srvtoolu_abc',
            'name' => 'advisor',
            'input' => [],
        ],
        [
            'type' => 'advisor_tool_result',
            'tool_use_id' => 'srvtoolu_abc',
            'content' => [
                'type' => 'advisor_result',
                'text' => 'Use a channel-based coordination pattern.',
            ],
        ],
        [
            'type' => 'tool_use',
            'id' => 'toolu_xyz',
            'name' => 'write_file',
            'input' => ['path' => 'worker.go'],
        ],
        ['type' => 'text', 'text' => "Here's the implementation."],
    ];

    $assistant = new AssistantMessage('Here\'s the implementation.', null, $contentBlocks);

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');
    $method->setAccessible(true);

    $mapped = $method->invoke($gateway, [$assistant]);

    expect($mapped)->toHaveCount(1)
        ->and($mapped[0]['role'])->toBe('assistant')
        ->and(array_column($mapped[0]['content'], 'type'))->toBe([
            'text',
            'server_tool_use',
            'advisor_tool_result',
            'tool_use',
            'text',
        ]);

    $serverToolUse = collect($mapped[0]['content'])->firstWhere('type', 'server_tool_use');
    expect($serverToolUse['input'])->toBeInstanceOf(stdClass::class);
});

test('parsed response populates contentBlocks on the assistant message', function () {
    // Non-streaming parser should carry the raw ordered block array through
    // onto AssistantMessage so downstream replay can reuse it verbatim.
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Consulted the advisor.'],
                [
                    'type' => 'server_tool_use',
                    'id' => 'srvtoolu_1',
                    'name' => 'advisor',
                    'input' => (object) [],
                ],
                [
                    'type' => 'advisor_tool_result',
                    'tool_use_id' => 'srvtoolu_1',
                    'content' => ['type' => 'advisor_result', 'text' => 'Proceed.'],
                ],
                ['type' => 'text', 'text' => 'Done.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('hi', provider: 'anthropic');

    $assistant = $response->messages->whereInstanceOf(AssistantMessage::class)->first();

    expect($assistant)->not->toBeNull()
        ->and(array_column($assistant->contentBlocks, 'type'))->toBe([
            'text',
            'server_tool_use',
            'advisor_tool_result',
            'text',
        ]);
});

test('assistant message produced by parser round-trips through mapping with server blocks intact', function () {
    // End-to-end: parse a response containing provider blocks, feed the
    // returned AssistantMessage back into mapMessages, confirm the outgoing
    // content preserves server_tool_use + server_tool_result + ordering.
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Searching.'],
                [
                    'type' => 'server_tool_use',
                    'id' => 'srvtoolu_1',
                    'name' => 'web_search',
                    'input' => (object) ['query' => 'laravel ai'],
                ],
                [
                    'type' => 'web_search_tool_result',
                    'tool_use_id' => 'srvtoolu_1',
                    'content' => [['title' => 'Laravel', 'url' => 'https://laravel.com']],
                ],
                ['type' => 'text', 'text' => 'Found it.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('search laravel', provider: 'anthropic');
    $assistant = $response->messages->whereInstanceOf(AssistantMessage::class)->first();

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');
    $method->setAccessible(true);

    $mapped = $method->invoke($gateway, [$assistant]);

    expect(array_column($mapped[0]['content'], 'type'))->toBe([
        'text',
        'server_tool_use',
        'web_search_tool_result',
        'text',
    ]);
});

test('assistant message without contentBlocks falls back to text plus tool calls rebuild', function () {
    $assistant = new AssistantMessage('Hello');

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');
    $method->setAccessible(true);

    $mapped = $method->invoke($gateway, [$assistant]);

    expect($mapped[0]['role'])->toBe('assistant')
        ->and($mapped[0]['content'])->toBe([
            ['type' => 'text', 'text' => 'Hello'],
        ]);
});

test('empty tool arguments serialize as object on assistant replay', function () {
    $assistant = new AssistantMessage('Listing.', collect([
        new ToolCall(
            id: 'toolu_empty',
            name: 'ListTool',
            arguments: [],
        ),
    ]));

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');
    $method->setAccessible(true);

    $mapped = $method->invoke($gateway, [$assistant]);
    $toolUse = collect($mapped[0]['content'])->firstWhere('type', 'tool_use');

    expect($toolUse['input'])->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($toolUse['input']))->toBeEmpty();
});

test('non-empty tool arguments preserve shape on assistant replay', function () {
    $assistant = new AssistantMessage('Searching.', collect([
        new ToolCall(
            id: 'toolu_args',
            name: 'SearchTool',
            arguments: ['query' => 'test'],
        ),
    ]));

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');
    $method->setAccessible(true);

    $mapped = $method->invoke($gateway, [$assistant]);
    $toolUse = collect($mapped[0]['content'])->firstWhere('type', 'tool_use');

    expect($toolUse['input'])->toBe(['query' => 'test']);
});

test('system instructions are not in messages array', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        foreach ($body['messages'] as $message) {
            if ($message['role'] === 'system') {
                return false;
            }
        }

        return isset($body['system']) && is_string($body['system']);
    });
});
