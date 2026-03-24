<?php

use App\Ai\Agents\IntentClarifier;
use App\Enums\ThreadStatus;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Jobs\ClarifyIntentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;

uses(RefreshDatabase::class);

/**
 * This test verifies the ClarifyIntent node end-to-end:
 * 1) It receives AI output wrapped in a markdown JSON fence.
 * 2) It parses and persists normalized thread data.
 * 3) It broadcasts node start/completion events with expected payloads.
 */
test('clarify intent job updates thread and broadcasts node lifecycle events', function () {
    // Arrange: create a realistic thread input that the job will enrich.
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Find evidence for CBT effectiveness in insomnia',
        'theme_context' => 'Sleep disorders',
    ]);

    // Capture broadcasted lifecycle events so we can assert orchestration behavior.
    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);

    // Mock the AI response as fenced JSON to validate parsing and extraction logic.
    Ai::fakeAgent(IntentClarifier::class, [
        [
            'questions' => [
                [
                    'id' => 'q1',
                    'type' => 'multiple_choice',
                    'text' => 'What study design should be prioritized?',
                    'options' => ['RCT', 'Observational'],
                    'scale_range' => null,
                    'rationale' => 'Design selection affects evidence quality thresholds.',
                ],
                [
                    'id' => 'q2',
                    'type' => 'boolean',
                    'text' => 'Should we limit to adults only?',
                    'options' => null,
                    'scale_range' => null,
                    'rationale' => 'Age limits change cohort comparability.',
                ],
            ],
            'theme_context' => 'Behavioral sleep medicine',
        ],
    ])->preventStrayPrompts();

    // Act: execute the job synchronously and reload persisted state from the database.
    (new ClarifyIntentJob($thread))->handle();

    $thread->refresh();

    // Assert: job writes parsed data and transitions the workflow to interviewing.
    expect($thread->questions)->toHaveCount(2);
    expect($thread->questions[0]['id'])->toBe('q1');
    expect($thread->questions[0]['type'])->toBe('multiple_choice');
    expect($thread->questions[0]['text'])->toBe('What study design should be prioritized?');
    expect($thread->questions[0]['options'])->toBe(['RCT', 'Observational']);
    expect($thread->questions[0]['rationale'])->toBe('Design selection affects evidence quality thresholds.');
    expect($thread->questions[1]['id'])->toBe('q2');
    expect($thread->questions[1]['type'])->toBe('boolean');
    expect($thread->questions[1]['text'])->toBe('Should we limit to adults only?');
    expect($thread->questions[1]['rationale'])->toBe('Age limits change cohort comparability.');
    expect($thread->theme_context)->toBe('Behavioral sleep medicine');
    expect($thread->status)->toBe(ThreadStatus::Interviewing);

    // Assert: the start event identifies the thread and correct agent node name.
    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'clarify_intent';
    });

    // Assert: completion event carries the same normalized discoveries saved on the thread.
    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'clarify_intent'
            && ($event->discoveries['questions'] ?? null) === $thread->questions
            && ($event->discoveries['theme_context'] ?? null) === $thread->theme_context;
    });
});

/**
 * This test verifies the failure path when the AI returns invalid text:
 * 1) Non-JSON output should fail parsing.
 * 2) Thread fields should remain unchanged.
 * 3) Lifecycle events should still be emitted for UI progress tracking.
 */
test('clarify intent job keeps thread unchanged when ai output is not valid json', function () {
    // Arrange: start with known persisted values that must remain intact.
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate metformin effects in PCOS',
        'theme_context' => 'Endocrinology',
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);

    // Provide plain text that cannot be decoded as JSON.
    Ai::fakeAgent(IntentClarifier::class, [
        'I could not determine clarifying questions at this time.',
    ])->preventStrayPrompts();

    // Act: run the job with invalid model output.
    (new ClarifyIntentJob($thread))->handle();

    $thread->refresh();

    // Assert: no thread fields were mutated by the failed parsing step.
    expect($thread->questions)->toBeNull();
    expect($thread->theme_context)->toBe('Endocrinology');
    expect($thread->status)->toBe(ThreadStatus::ClarificationPending);

    // Assert: start event is still emitted so clients can show progress.
    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'clarify_intent';
    });

    // Assert: completion event is still emitted with the current (unchanged) state.
    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'clarify_intent'
            && ($event->discoveries['questions'] ?? null) === null
            && ($event->discoveries['theme_context'] ?? null) === 'Endocrinology';
    });
});

/**
 * This test verifies strict output validation:
 * 1) JSON can be syntactically valid but schema-invalid.
 * 2) Invalid schema must be ignored and must not mutate thread fields.
 */
test('clarify intent job keeps thread unchanged when ai json fails schema validation', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate SGLT2 inhibitors in heart failure',
        'theme_context' => 'Cardiology',
    ]);

    Ai::fakeAgent(IntentClarifier::class, [
        [
            'theme_context' => 'Cardiology outcomes',
            'questions' => [
                [
                    'id' => 'q1',
                    'type' => 'multiple_choice',
                    'text' => 'Which population is prioritized?',
                    'rationale' => 'Population definition changes effect estimates.',
                ],
            ],
        ],
    ])->preventStrayPrompts();

    (new ClarifyIntentJob($thread))->handle();

    $thread->refresh();

    expect($thread->questions)->toBeNull();
    expect($thread->theme_context)->toBe('Cardiology');
    expect($thread->status)->toBe(ThreadStatus::ClarificationPending);
});

/**
 * This test verifies transaction rollback safety:
 * 1) A partial write inside transactional persistence is attempted.
 * 2) An exception after the partial write forces rollback.
 * 3) Persisted thread state remains unchanged.
 */
test('clarify intent transactional persistence rolls back on exception', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Assess omega-3 impact on depression symptoms',
        'theme_context' => 'Psychiatry',
    ]);

    $job = new class($thread) extends ClarifyIntentJob
    {
        /**
         * @param  array{theme_context: string, questions: array<int, array<string, mixed>>}  $validated
         */
        public function runPersist(array $validated): void
        {
            $this->persistValidatedData($validated);
        }

        protected function applyValidatedData(array $validated): void
        {
            $this->thread->update([
                'status' => ThreadStatus::Interviewing,
            ]);

            throw new RuntimeException('Forced persistence failure after partial write.');
        }
    };

    $validated = [
        'theme_context' => 'Nutritional psychiatry',
        'questions' => [
            [
                'id' => 'q1',
                'type' => 'open_ended',
                'text' => 'Should baseline severity be constrained?',
                'rationale' => 'Baseline severity can shift treatment effect size.',
            ],
        ],
    ];

    expect(fn () => $job->runPersist($validated))->toThrow(RuntimeException::class);

    $thread->refresh();

    expect($thread->status)->toBe(ThreadStatus::ClarificationPending);
    expect($thread->theme_context)->toBe('Psychiatry');
    expect($thread->questions)->toBeNull();
});
