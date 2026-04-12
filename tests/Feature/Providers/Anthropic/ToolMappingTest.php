<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\Advisor;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

test('tool parameters are not wrapped in schema definition', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $tool) {
            if ($tool['name'] === 'FixedNumberGenerator') {
                $properties = (array) ($tool['input_schema']['properties'] ?? []);

                return $tool['input_schema']['type'] === 'object'
                    && ! isset($properties['schema_definition']);
            }
        }

        return false;
    });
});

test('unsupported provider tool throws logic exception', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    agent(
        'Test unsupported tool',
        tools: [new FileSearch(['store_1'])],
    )->prompt(
        'Search for something',
        provider: 'anthropic',
    );
})->throws(LogicException::class, 'is not supported by Anthropic');

test('tool with a name() method emits the declared name', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

        return in_array('aliased_tool', $names, true);
    });
});

test('web search tool sends allowed_domains', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [(new WebSearch)->allow(['laravel.com', 'php.net'])])
        ->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

        return data_get($tool, 'allowed_domains') === ['laravel.com', 'php.net'];
    });
});

test('web search tool forwards anthropic provider options into the tool payload', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [
        (new WebSearch)->withProviderOptions(['blocked_domains' => ['spam.com']]),
    ])->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

        return data_get($tool, 'blocked_domains') === ['spam.com'];
    });
});

test('advisor tool maps to advisor_20260301 definition with model', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(
        'Test advisor',
        tools: [new Advisor(model: 'claude-opus-4-6')],
    )->prompt('plan', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $advisor = collect($request->data()['tools'] ?? [])->firstWhere('type', 'advisor_20260301');

        return $advisor !== null
            && $advisor['name'] === 'advisor'
            && $advisor['model'] === 'claude-opus-4-6'
            && ! isset($advisor['max_uses'])
            && ! isset($advisor['caching']);
    });
});

test('advisor tool passes max_uses and caching when set', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(
        'Test advisor',
        tools: [new Advisor(model: 'claude-opus-4-6', maxUses: 4, cacheTtl: '1h')],
    )->prompt('plan', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $advisor = collect($request->data()['tools'] ?? [])->firstWhere('type', 'advisor_20260301');

        return $advisor['max_uses'] === 4
            && $advisor['caching'] === ['type' => 'ephemeral', 'ttl' => '1h'];
    });
});

test('advisor constructor rejects invalid cacheTtl', function () {
    new Advisor(model: 'claude-opus-4-6', cacheTtl: '10m');
})->throws(InvalidArgumentException::class);

test('advisor constructor rejects empty model', function () {
    new Advisor(model: '');
})->throws(InvalidArgumentException::class);

test('empty schema still includes input schema with type object', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $tool) {
            if ($tool['name'] === 'FixedNumberGenerator') {
                return isset($tool['input_schema'])
                    && $tool['input_schema']['type'] === 'object'
                    && isset($tool['input_schema']['properties']);
            }
        }

        return false;
    });
});
