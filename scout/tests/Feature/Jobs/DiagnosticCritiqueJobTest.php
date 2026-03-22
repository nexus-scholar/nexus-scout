<?php

use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Jobs\DiagnosticCritiqueJob;
use App\Jobs\ValidateProtocolJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;

uses(RefreshDatabase::class);

/**
 * This test verifies successful critique/rebuild behavior:
 * 1) AI rebuilt themes replace query_themes.
 * 2) query_string values are flattened to boolean_strings.
 * 3) critique summary is persisted.
 * 4) lifecycle events fire and ValidateProtocolJob is dispatched.
 */
test('diagnostic critique job rebuilds queries and dispatches validate protocol', function () {
    $user = User::factory()->create();

    $thread = $user->threads()->create([
        'objective' => 'Assess SSRI outcomes in adolescent depression',
        'theme_context' => 'Child psychiatry',
        'state_data' => [
            'query_themes' => [
                [
                    'name' => 'Narrow baseline',
                    'queries' => [
                        ['id' => 'old-1', 'query_string' => '"SSRI" AND "adolescent depression" AND placebo', 'target_fields' => ['Title', 'Abstract']],
                    ],
                ],
            ],
            'missing_seeds' => ['10.1001/jamapsychiatry.2023.0001'],
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    Ai::fakeAgent(AnonymousAgent::class, [
        <<<'JSON'
```json
{
  "themes": [
    {
      "name": "Broadened antidepressant semantics",
      "queries": [
        {
          "id": "new-1",
          "query_string": "(SSRI OR \"selective serotonin reuptake inhibitor\") AND (adolescent OR youth) AND depression",
          "target_fields": ["Title", "Abstract"]
        },
        {
          "id": "new-2",
          "query_string": "(fluoxetine OR sertraline) AND adolescent depression",
          "target_fields": ["Title", "Abstract"]
        }
      ]
    }
  ]
}
```
JSON,
    ])->preventStrayPrompts();

    (new DiagnosticCritiqueJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data)->toHaveKey('query_themes');
    expect($thread->state_data['query_themes'])->toHaveCount(1);
    expect($thread->state_data['query_themes'][0]['name'])->toBe('Broadened antidepressant semantics');
    expect($thread->state_data['boolean_strings'])->toBe([
        '(SSRI OR "selective serotonin reuptake inhibitor") AND (adolescent OR youth) AND depression',
        '(fluoxetine OR sertraline) AND adolescent depression',
    ]);
    expect($thread->state_data['critique'] ?? null)->toBe('Analyzed missing seeds and broadening query structure.');

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'diagnostic_critique';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'diagnostic_critique'
            && ($event->discoveries['critique'] ?? null) === 'Analyzed missing seeds and broadening query structure.';
    });

    Queue::assertPushed(ValidateProtocolJob::class, function (ValidateProtocolJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});

/**
 * This test verifies invalid-output resilience:
 * 1) Non-JSON output does not mutate rebuilt query fields.
 * 2) completion payload falls back to default message.
 * 3) ValidateProtocolJob is still dispatched.
 */
test('diagnostic critique job tolerates invalid ai output and still advances pipeline', function () {
    $user = User::factory()->create();

    $thread = $user->threads()->create([
        'objective' => 'Assess CBT outcomes in resistant insomnia',
        'theme_context' => 'Sleep medicine',
        'state_data' => [
            'query_themes' => [
                [
                    'name' => 'Original theme',
                    'queries' => [
                        ['id' => 'old-1', 'query_string' => 'insomnia AND CBT', 'target_fields' => ['Title', 'Abstract']],
                    ],
                ],
            ],
            'boolean_strings' => ['insomnia AND CBT'],
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    Ai::fakeAgent(AnonymousAgent::class, [
        'Unable to produce revised query bundle.',
    ])->preventStrayPrompts();

    (new DiagnosticCritiqueJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data['query_themes'][0]['name'] ?? null)->toBe('Original theme');
    expect($thread->state_data['boolean_strings'] ?? null)->toBe(['insomnia AND CBT']);
    expect($thread->state_data)->not->toHaveKey('critique');

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'diagnostic_critique'
            && ($event->discoveries['critique'] ?? null) === 'Queries rebuilt.';
    });

    Queue::assertPushed(ValidateProtocolJob::class, function (ValidateProtocolJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});
