<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\ImageGeneration;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;
use Tests\Fixtures\Tools\NonStrictTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes strict compliant schema', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return $tool['strict'] === true
            && $tool['parameters']['type'] === 'object'
            && array_key_exists('min', $tool['parameters']['properties'])
            && array_key_exists('max', $tool['parameters']['properties'])
            && in_array('min', $tool['parameters']['required'])
            && in_array('max', $tool['parameters']['required'])
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('tool with a name() method emits the declared name', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('name')->all();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool without a name() method falls back to class basename for openai', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Hi', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('name')->all();

        return in_array('FixedNumberGenerator', $names, true);
    });
});

test('tool without Strict attribute sends strict false and honors developer-declared required fields', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new NonStrictTool])->prompt('Hi', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return $tool['strict'] === false
            && $tool['parameters']['required'] === ['query']
            && array_key_exists('limit', $tool['parameters']['properties']);
    });
});

test('tool with empty schema includes strict compliant parameters', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return $tool['strict'] === true
            && array_key_exists('parameters', $tool)
            && $tool['parameters']['type'] === 'object'
            && $tool['parameters']['properties'] === []
            && $tool['parameters']['required'] === []
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('image generation provider tool maps to openai image_generation type', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Here is the image'),
    ]);

    agent(tools: [(new ImageGeneration(quality: 'high', size: '1024x1024', partialImages: 2))->format('webp')->compression(80)])->prompt('Generate an image of a cat', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'image_generation');

        return $tool !== null
            && $tool['quality'] === 'high'
            && $tool['size'] === '1024x1024'
            && $tool['partial_images'] === 2
            && $tool['output_format'] === 'webp'
            && $tool['output_compression'] === 80;
    });
});

test('image generation provider tool with defaults sends minimal config', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Here is the image'),
    ]);

    agent(tools: [new ImageGeneration])->prompt('Generate an image', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'image_generation');

        return $tool !== null
            && $tool === ['type' => 'image_generation'];
    });
});

test('image generation preserves falsy values like zero', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Here is the image'),
    ]);

    agent(tools: [new ImageGeneration(partialImages: 0, outputCompression: 0)])->prompt('Generate an image', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'image_generation');

        return $tool !== null
            && array_key_exists('partial_images', $tool)
            && $tool['partial_images'] === 0
            && array_key_exists('output_compression', $tool)
            && $tool['output_compression'] === 0;
    });
});

test('image generation options bag passes through provider-specific params', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Here is the image'),
    ]);

    agent(tools: [
        (new ImageGeneration(quality: 'high'))->withOptions([
            'model' => 'gpt-image-1',
            'moderation' => 'low',
            'input_fidelity' => 'high',
        ]),
    ])->prompt('Generate an image', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'image_generation');

        return $tool !== null
            && $tool['quality'] === 'high'
            && $tool['model'] === 'gpt-image-1'
            && $tool['moderation'] === 'low'
            && $tool['input_fidelity'] === 'high';
    });
});

test('image generation options bag cannot override type', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Here is the image'),
    ]);

    agent(tools: [
        (new ImageGeneration)->withOptions(['type' => 'should_be_stripped']),
    ])->prompt('Generate an image', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'image_generation');

        return $tool !== null
            && $tool['type'] === 'image_generation';
    });
});

test('image generation mask with no args omits input_image_mask', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Here is the image'),
    ]);

    agent(tools: [(new ImageGeneration)->mask()])->prompt('Generate an image', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'image_generation');

        return $tool !== null
            && ! array_key_exists('input_image_mask', $tool);
    });
});

test('image generation rejects partial_images out of range', function (int $value) {
    new ImageGeneration(partialImages: $value);
})->with([
    'negative' => [-1],
    'above max' => [4],
])->throws(InvalidArgumentException::class, 'partial_images must be between 0 and 3');

test('image generation rejects output_compression out of range', function (int $value) {
    new ImageGeneration(outputCompression: $value);
})->with([
    'negative' => [-1],
    'above max' => [101],
])->throws(InvalidArgumentException::class, 'output_compression must be between 0 and 100');
