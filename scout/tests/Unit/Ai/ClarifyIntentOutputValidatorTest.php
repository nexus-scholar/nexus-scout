<?php

use App\Ai\ClarifyIntentOutputValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * These tests validate strict schema rules for ClarifyIntent output:
 * - Required top-level keys and types.
 * - Conditional fields (options / scale_range) per question type.
 * - Rejection of malformed payloads before they can be persisted.
 */
test('validator accepts a valid clarify intent payload', function () {
    $payload = [
        'theme_context' => 'Behavioral sleep medicine',
        'questions' => [
            [
                'id' => 'q1',
                'type' => 'multiple_choice',
                'text' => 'What population should be prioritized?',
                'options' => ['Adults', 'Adolescents'],
                'rationale' => 'Population scope changes search precision.',
            ],
            [
                'id' => 'q2',
                'type' => 'scale',
                'text' => 'How strict should inclusion criteria be?',
                'scale_range' => [1, 5],
                'rationale' => 'Scope strictness controls evidence breadth.',
            ],
        ],
    ];

    $validated = app(ClarifyIntentOutputValidator::class)->validate($payload);

    expect($validated['theme_context'])->toBe('Behavioral sleep medicine');
    expect($validated['questions'])->toHaveCount(2);
});

test('validator rejects payload missing required theme context', function () {
    $payload = [
        'questions' => [
            [
                'id' => 'q1',
                'type' => 'open_ended',
                'text' => 'What comparator should be used?',
                'rationale' => 'Comparator impacts interpretation.',
            ],
        ],
    ];

    expect(fn () => app(ClarifyIntentOutputValidator::class)->validate($payload))
        ->toThrow(ValidationException::class);
});

test('validator rejects choice question without options', function () {
    $payload = [
        'theme_context' => 'Clinical epidemiology',
        'questions' => [
            [
                'id' => 'q1',
                'type' => 'multiple_choice',
                'text' => 'Which primary outcome matters most?',
                'rationale' => 'Outcome choice guides search terms.',
            ],
        ],
    ];

    expect(fn () => app(ClarifyIntentOutputValidator::class)->validate($payload))
        ->toThrow(ValidationException::class);
});

test('validator rejects non scale question that includes scale range', function () {
    $payload = [
        'theme_context' => 'Cardiometabolic medicine',
        'questions' => [
            [
                'id' => 'q1',
                'type' => 'open_ended',
                'text' => 'Any subgroup constraints?',
                'scale_range' => [1, 7],
                'rationale' => 'Subgroups can constrain protocol assumptions.',
            ],
        ],
    ];

    expect(fn () => app(ClarifyIntentOutputValidator::class)->validate($payload))
        ->toThrow(ValidationException::class);
});
