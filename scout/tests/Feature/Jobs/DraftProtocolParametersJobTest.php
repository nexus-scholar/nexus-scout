<?php

use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Jobs\DraftProtocolParametersJob;
use App\Jobs\LexicalScoutJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;

uses(RefreshDatabase::class);

/**
 * This test validates the successful draft_protocol node behavior:
 * 1) Valid AI JSON is parsed and persisted under state_data.protocol_draft.
 * 2) Existing state_data keys are preserved.
 * 3) Lifecycle events are emitted and the next job is queued.
 */
test('draft protocol job stores protocol draft, broadcasts events, and dispatches lexical scout', function () {
    // Arrange: create a thread with prior state to verify merge behavior.
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate CBT efficacy for chronic insomnia',
        'theme_context' => 'Behavioral sleep medicine',
        'answers' => [
            'population' => 'Adults',
            'outcome' => 'Sleep latency',
        ],
        'state_data' => [
            'loop_count' => 2,
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    // Provide schema-compliant JSON wrapped in a markdown code fence.
    Ai::fakeAgent(AnonymousAgent::class, [
        <<<'JSON'
```json
{
  "scope": {
    "definition": "Randomized and quasi-experimental studies of CBT for adult chronic insomnia.",
    "rationale": "Focuses on intervention efficacy with clinically relevant comparators."
  },
  "inclusion": [
    {
      "criterion": "Adults diagnosed with chronic insomnia",
      "rationale": "Improves cohort comparability."
    }
  ],
  "exclusion": [
    {
      "criterion": "Non-peer-reviewed opinion pieces",
      "rationale": "Reduces low-evidence bias."
    }
  ]
}
```
JSON,
    ])->preventStrayPrompts();

    // Act: run the job synchronously.
    (new DraftProtocolParametersJob($thread))->handle();

    $thread->refresh();

    // Assert: protocol draft is persisted and previous state is preserved.
    expect($thread->state_data)->toHaveKey('loop_count', 2);
    expect($thread->state_data)->toHaveKey('protocol_draft');
    expect($thread->state_data['protocol_draft']['scope']['definition'])
        ->toBe('Randomized and quasi-experimental studies of CBT for adult chronic insomnia.');
    expect($thread->state_data['protocol_draft']['scope']['rationale'])
        ->toBe('Focuses on intervention efficacy with clinically relevant comparators.');
    expect($thread->state_data['protocol_draft']['inclusion'][0]['criterion'])
        ->toBe('Adults diagnosed with chronic insomnia');
    expect($thread->state_data['protocol_draft']['exclusion'][0]['criterion'])
        ->toBe('Non-peer-reviewed opinion pieces');

    // Assert: start/completion events include the expected node and payload.
    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'draft_protocol';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'draft_protocol'
            && ($event->discoveries['protocol']['scope']['definition'] ?? null)
                === ($thread->state_data['protocol_draft']['scope']['definition'] ?? null);
    });

    // Assert: pipeline advances to the next job without executing it in this test.
    Queue::assertPushed(LexicalScoutJob::class, function (LexicalScoutJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});

/**
 * This test validates failure-path behavior:
 * 1) Invalid/non-JSON output does not mutate state_data.protocol_draft.
 * 2) Lifecycle events still fire for UI progress tracking.
 * 3) The next job is still dispatched according to current pipeline behavior.
 */
test('draft protocol job ignores invalid ai output and still dispatches next job', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Assess metformin effects in adolescents with PCOS',
        'theme_context' => 'Endocrinology',
        'answers' => [
            'population' => 'Adolescents',
        ],
        'state_data' => [
            'loop_count' => 1,
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    Ai::fakeAgent(AnonymousAgent::class, [
        'I cannot provide a protocol right now.',
    ])->preventStrayPrompts();

    (new DraftProtocolParametersJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data)->toHaveKey('loop_count', 1);
    expect($thread->state_data)->not->toHaveKey('protocol_draft');

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'draft_protocol';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'draft_protocol'
            && ($event->discoveries['protocol'] ?? null) === [];
    });

    Queue::assertPushed(LexicalScoutJob::class, function (LexicalScoutJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});
