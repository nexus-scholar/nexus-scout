<?php

use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Events\AgentWorkflowFinished;
use App\Jobs\GenerateExportNodeJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * This test verifies export generation with available query data:
 * 1) nexus_yaml and protocol payloads are generated.
 * 2) thread status transitions to completed.
 * 3) node and workflow completion events are emitted with expected payloads.
 */
test('generate export node job creates export payloads and finishes workflow', function () {
    $user = User::factory()->create();

    $thread = $user->threads()->create([
        'objective' => 'Assess CBT outcomes in chronic insomnia',
        'theme_context' => 'Sleep medicine',
        'state_data' => [
            'boolean_strings' => [
                '("cognitive behavioral therapy" OR "CBT-I") AND insomnia',
            ],
            'pico_framework' => [
                'population' => 'Adults with chronic insomnia',
                'intervention' => 'CBT-I',
                'comparison' => 'Standard care',
                'outcome' => 'Sleep latency',
            ],
            'expanded_taxonomy' => [
                'CBT-I' => ['cognitive behavioral therapy for insomnia'],
            ],
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
        AgentWorkflowFinished::class,
    ]);

    (new GenerateExportNodeJob($thread))->handle();

    $thread->refresh();

    expect($thread->status)->toBe('completed');
    expect($thread->nexus_yaml)->toContain('project:');
    expect($thread->nexus_yaml)->toContain((string) $thread->id);
    expect($thread->nexus_yaml)->toContain($thread->objective);
    expect($thread->nexus_yaml)->toContain('("cognitive behavioral therapy" OR "CBT-I") AND insomnia');

    expect($thread->protocol)->toHaveKeys(['nexus_yml', 'queries_yml', 'protocol_json']);
    expect($thread->protocol['protocol_json']['pico']['population'] ?? null)
        ->toBe('Adults with chronic insomnia');
    expect($thread->protocol['protocol_json']['taxonomy']['CBT-I'][0] ?? null)
        ->toBe('cognitive behavioral therapy for insomnia');

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'generate_export';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'generate_export'
            && ($event->discoveries['payloads'] ?? null) === ['nexus_yml', 'queries_yml', 'protocol_json'];
    });

    Event::assertDispatched(AgentWorkflowFinished::class, function (AgentWorkflowFinished $event) use ($thread): bool {
        return $event->thread->is($thread)
            && ($event->payload['yaml'] ?? null) === $thread->nexus_yaml;
    });
});

/**
 * This test verifies fallback behavior when boolean_strings are missing:
 * export still completes with an empty boolean block instead of failing.
 */
test('generate export node job completes when boolean strings are missing', function () {
    $user = User::factory()->create();

    $thread = $user->threads()->create([
        'objective' => 'Evaluate metformin efficacy in PCOS',
        'theme_context' => 'Endocrinology',
        'state_data' => [
            'pico_framework' => [],
            'expanded_taxonomy' => [],
        ],
    ]);

    Event::fake([
        AgentNodeCompleted::class,
        AgentWorkflowFinished::class,
    ]);

    (new GenerateExportNodeJob($thread))->handle();

    $thread->refresh();

    expect($thread->status)->toBe('completed');
    expect($thread->nexus_yaml)->toContain('boolean_string');
    expect($thread->protocol['queries_yml'] ?? null)->toContain('queries:');

    Event::assertDispatched(AgentNodeCompleted::class);
    Event::assertDispatched(AgentWorkflowFinished::class);
});
