<?php

use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Jobs\GenerateQueriesJob;
use App\Jobs\ValidateProtocolJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;

uses(RefreshDatabase::class);

/**
 * This test verifies the generate_queries success flow:
 * 1) Valid AI JSON is parsed into query_themes.
 * 2) query_string values are flattened into boolean_strings.
 * 3) Existing state_data remains intact.
 * 4) Lifecycle events are emitted and ValidateProtocolJob is dispatched.
 */
test('generate queries job stores themes and boolean strings, broadcasts events, and dispatches validate protocol', function () {
    // Arrange: prepare a thread with protocol and taxonomy context used by the prompt.
    $user = User::factory()->create();

    $thread = $user->threads()->create([
        'objective' => 'Assess CBT outcomes in insomnia',
        'theme_context' => 'Sleep medicine',
        'state_data' => [
            'protocol_draft' => [
                'scope' => [
                    'definition' => 'Adult insomnia populations receiving CBT interventions.',
                    'rationale' => 'Focuses on clinically relevant treatment effects.',
                ],
                'inclusion' => [
                    ['criterion' => 'Randomized trials', 'rationale' => 'Higher internal validity'],
                ],
                'exclusion' => [
                    ['criterion' => 'Case reports', 'rationale' => 'Low evidence quality'],
                ],
            ],
            'expanded_taxonomy' => [
                'CBT' => ['cognitive behavioral therapy', 'CBT-I'],
                'Insomnia' => ['sleep initiation disorder', 'sleep maintenance disorder'],
            ],
            'loop_count' => 1,
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    // Provide schema-compliant query themes with multiple query strings to flatten.
    Ai::fakeAgent(AnonymousAgent::class, [
        <<<'JSON'
```json
{
  "themes": [
    {
      "name": "CBT efficacy",
      "queries": [
        {
          "id": "q1",
          "query_string": "(\"cognitive behavioral therapy\" OR \"CBT-I\") AND insomnia",
          "target_fields": ["Title", "Abstract"]
        },
        {
          "id": "q2",
          "query_string": "(CBT OR \"behavioral sleep therapy\") AND \"sleep latency\"",
          "target_fields": ["Title", "Abstract"]
        }
      ]
    },
    {
      "name": "Comparative outcomes",
      "queries": [
        {
          "id": "q3",
          "query_string": "insomnia AND (CBT OR pharmacotherapy) AND randomized",
          "target_fields": ["Title", "Abstract"]
        }
      ]
    }
  ]
}
```
JSON,
    ])->preventStrayPrompts();

    // Act: run the job synchronously.
    (new GenerateQueriesJob($thread))->handle();

    $thread->refresh();

    // Assert: themes are saved and flattened boolean strings are generated in order.
    expect($thread->state_data)->toHaveKey('loop_count', 1);
    expect($thread->state_data)->toHaveKey('query_themes');
    expect($thread->state_data['query_themes'])->toHaveCount(2);
    expect($thread->state_data['query_themes'][0]['name'])->toBe('CBT efficacy');
    expect($thread->state_data['boolean_strings'])->toBe([
        '("cognitive behavioral therapy" OR "CBT-I") AND insomnia',
        '(CBT OR "behavioral sleep therapy") AND "sleep latency"',
        'insomnia AND (CBT OR pharmacotherapy) AND randomized',
    ]);

    // Assert: lifecycle events include expected node and completion payload.
    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'generate_queries';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'generate_queries'
            && ($event->discoveries['themes'][0]['name'] ?? null)
                === ($thread->state_data['query_themes'][0]['name'] ?? null);
    });

    // Assert: pipeline advances to validation stage.
    Queue::assertPushed(ValidateProtocolJob::class, function (ValidateProtocolJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});

/**
 * This test verifies failure-path resilience:
 * 1) Invalid AI output does not mutate query_themes/boolean_strings.
 * 2) Completion payload falls back to an empty themes array.
 * 3) Pipeline still dispatches ValidateProtocolJob.
 */
test('generate queries job ignores invalid output and still dispatches validate protocol', function () {
    $user = User::factory()->create();

    $thread = $user->threads()->create([
        'objective' => 'Assess pharmacologic interventions in chronic insomnia',
        'theme_context' => 'Sleep pharmacotherapy',
        'state_data' => [
            'protocol_draft' => [
                'scope' => ['definition' => 'Insomnia treatments in adults'],
            ],
            'expanded_taxonomy' => [
                'Hypnotics' => ['eszopiclone', 'zolpidem'],
            ],
            'loop_count' => 2,
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    Ai::fakeAgent(AnonymousAgent::class, [
        'Malformed query generation response',
    ])->preventStrayPrompts();

    (new GenerateQueriesJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data)->toHaveKey('loop_count', 2);
    expect($thread->state_data)->not->toHaveKey('query_themes');
    expect($thread->state_data)->not->toHaveKey('boolean_strings');

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'generate_queries';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'generate_queries'
            && ($event->discoveries['themes'] ?? null) === [];
    });

    Queue::assertPushed(ValidateProtocolJob::class, function (ValidateProtocolJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});
