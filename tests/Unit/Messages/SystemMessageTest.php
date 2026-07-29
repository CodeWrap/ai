<?php

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\SystemMessage;

test('system message sets role to system', function () {
    $message = new SystemMessage('You are an expert.');

    expect($message->role)->toBe(MessageRole::System)
        ->and($message->content)->toBe('You are an expert.');
});

test('message role resolves system from string', function () {
    expect(MessageRole::from('system'))->toBe(MessageRole::System);
});

test('generic message can be created with system role', function () {
    $message = new Message('system', 'Instructions here.');

    expect($message->role)->toBe(MessageRole::System)
        ->and($message->content)->toBe('Instructions here.');
});

test('system message can be created via Message tryFrom', function () {
    $message = Message::tryFrom(['role' => 'system', 'content' => 'Be concise.']);

    expect($message->role)->toBe(MessageRole::System)
        ->and($message->content)->toBe('Be concise.');
});
