<?php

use App\Ai\PromptManager;
use Tests\TestCase;

uses(TestCase::class);

test('prompt manager returns clarify intent system and user prompt strings', function () {
    // Arrange: provide objective and optional theme context passed into Blade prompts.
    $objective = 'Assess melatonin efficacy for delayed sleep phase disorder';
    $themeContext = 'Circadian rhythm disorders';

    // Act: render prompts for the clarify_intent job.
    $prompts = PromptManager::getPrompts('clarify_intent', [
        'objective' => $objective,
        'theme_context' => $themeContext,
    ]);

    // Assert: output shape is stable and ready for AI agent instructions/user message.
    expect($prompts)->toHaveKeys(['system', 'user']);
    expect($prompts['system'])->toBeString()->not->toBe('');
    expect($prompts['user'])->toBeString()->not->toBe('');

    // Assert: user prompt includes interpolated objective and existing theme context.
    expect($prompts['user'])->toContain('Objective: '.$objective);
    expect($prompts['user'])->toContain('Existing Theme: '.$themeContext);
});

test('prompt manager defaults existing theme to none when theme context is omitted', function () {
    // Act: render prompt data without theme_context.
    $prompts = PromptManager::getPrompts('clarify_intent', [
        'objective' => 'Determine best screening strategy for prediabetes',
    ]);

    // Assert: Blade fallback in clarify_intent_user prompt is applied.
    expect($prompts['user'])->toContain('Existing Theme: None');
});
