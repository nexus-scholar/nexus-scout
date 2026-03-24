<?php

use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Jobs\GenerateQueriesJob;
use App\Jobs\LexicalScoutJob;
use App\Models\User;
use App\Services\NexusApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;

uses(RefreshDatabase::class);

/**
 * This test verifies the lexical_scout success flow:
 * 1) PICO extraction output is parsed and persisted.
 * 2) Taxonomy summarization output is parsed and persisted.
 * 3) Existing protocol_draft data is preserved.
 * 4) Lifecycle events are emitted and GenerateQueriesJob is dispatched.
 */
test('lexical scout job stores pico and taxonomy, broadcasts events, and dispatches generate queries', function () {
    // Arrange: create a thread with a protocol draft that lexical_scout consumes.
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Assess SSRI efficacy in adolescents with ME/CFS',
        'theme_context' => 'Pediatric chronic fatigue',
        'state_data' => [
            'protocol_draft' => [
                'scope' => [
                    'definition' => 'Adolescent studies evaluating SSRI efficacy in ME/CFS cohorts.',
                    'rationale' => 'Targets a clinically specific and vulnerable population.',
                ],
                'inclusion' => [
                    [
                        'criterion' => 'Participants aged 12-18 with ME/CFS diagnosis',
                        'rationale' => 'Ensures population relevance.',
                    ],
                ],
                'exclusion' => [
                    [
                        'criterion' => 'Case reports',
                        'rationale' => 'Insufficient evidence strength.',
                    ],
                ],
            ],
            'loop_count' => 0,
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

        $papersFromNexus = [
                [
                        'title' => 'Fluoxetine in adolescents with ME/CFS',
                        'abstract' => 'Evaluates functional outcomes and fatigue severity over 12 weeks.',
                ],
                [
                        'title' => 'Sertraline and fatigue recovery trajectories',
                        'abstract' => 'Investigates symptom trajectories in pediatric ME/CFS cohorts.',
                ],
        ];

        $nexusClient = Mockery::mock(NexusApiClient::class);
        $nexusClient->shouldReceive('searchLiterature')
                ->once()
                ->withArgs(function (array $pico, array $options): bool {
                        return ($pico['population'] ?? null) === 'Adolescents with ME/CFS'
                                && ($pico['intervention'] ?? null) === 'SSRIs'
                                && ($options['limit'] ?? null) === 15;
                })
                ->andReturn($papersFromNexus);
        app()->instance(NexusApiClient::class, $nexusClient);

        // AI fake is dynamic so we can assert Nexus-returned papers were passed into taxonomy prompt phase.
        Ai::fakeAgent(AnonymousAgent::class, function (string $prompt) {
                if (str_contains($prompt, 'Extract the PICO elements from the above protocol information.')) {
                        return <<<'JSON'
```json
{
    "population": "Adolescents with ME/CFS",
    "intervention": "SSRIs",
    "comparison": "Placebo or standard care",
    "outcome": "Fatigue severity and functional outcomes"
}
```
JSON;
                }

                expect($prompt)->toContain('Fluoxetine in adolescents with ME/CFS');
                expect($prompt)->toContain('Sertraline and fatigue recovery trajectories');

                return <<<'JSON'
```json
{
    "expanded_taxonomy": {
        "SSRIs": ["selective serotonin reuptake inhibitors", "fluoxetine", "sertraline"],
        "ME/CFS": ["myalgic encephalomyelitis", "chronic fatigue syndrome", "ME CFS"]
    }
}
```
JSON;
        })->preventStrayPrompts();

    // Act: execute lexical_scout synchronously.
    (new LexicalScoutJob($thread))->handle();

    $thread->refresh();

    // Assert: prior state is preserved and new fields are persisted.
    expect($thread->state_data)->toHaveKey('protocol_draft');
    expect($thread->state_data)->toHaveKey('loop_count', 0);

    expect($thread->state_data['pico_framework']['population'])->toBe('Adolescents with ME/CFS');
    expect($thread->state_data['pico_framework']['intervention'])->toBe('SSRIs');
    expect($thread->state_data['pico_framework']['comparison'])->toBe('Placebo or standard care');
    expect($thread->state_data['pico_framework']['outcome'])->toBe('Fatigue severity and functional outcomes');

    expect($thread->state_data['expanded_taxonomy'])->toHaveKeys(['SSRIs', 'ME/CFS']);
    expect($thread->state_data['expanded_taxonomy']['SSRIs'])->toContain('fluoxetine');

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'lexical_scout';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'lexical_scout'
            && ($event->discoveries['pico']['population'] ?? null)
                === ($thread->state_data['pico_framework']['population'] ?? null)
            && ($event->discoveries['taxonomy']['SSRIs'][0] ?? null)
                === ($thread->state_data['expanded_taxonomy']['SSRIs'][0] ?? null);
    });

    Queue::assertPushed(GenerateQueriesJob::class, function (GenerateQueriesJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});

/**
 * This test verifies failure-path resilience:
 * 1) Invalid AI output in both phases is tolerated.
 * 2) pico_framework and expanded_taxonomy fall back to empty arrays.
 * 3) Pipeline still emits lifecycle events and dispatches next job.
 */
test('lexical scout job tolerates invalid ai output and still advances pipeline', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate CBT outcomes in treatment-resistant insomnia',
        'theme_context' => 'Sleep medicine',
        'state_data' => [
            'protocol_draft' => [
                'scope' => [
                    'definition' => 'Adults with treatment-resistant insomnia treated with CBT variants.',
                    'rationale' => 'Focused on difficult-to-treat cohorts.',
                ],
                'inclusion' => [
                    [
                        'criterion' => 'Randomized trials',
                        'rationale' => 'Higher internal validity.',
                    ],
                ],
            ],
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    $nexusClient = Mockery::mock(NexusApiClient::class);
    $nexusClient->shouldReceive('searchLiterature')
        ->once()
        ->andReturn([
            ['title' => 'Paper A', 'abstract' => 'Abstract A'],
        ]);
    app()->instance(NexusApiClient::class, $nexusClient);

    Ai::fakeAgent(AnonymousAgent::class, [
        'Malformed PICO response',
        'Malformed taxonomy response',
    ])->preventStrayPrompts();

    (new LexicalScoutJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data['pico_framework'] ?? null)->toBe([]);
    expect($thread->state_data['expanded_taxonomy'] ?? null)->toBe([]);

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'lexical_scout';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'lexical_scout'
            && ($event->discoveries['pico'] ?? null) === []
            && ($event->discoveries['taxonomy'] ?? null) === [];
    });

    Queue::assertPushed(GenerateQueriesJob::class, function (GenerateQueriesJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});

/**
 * This test simulates a live-like node run:
 * 1) AI generates PICO.
 * 2) Nexus client returns papers.
 * 3) AI summarizes taxonomy from those papers.
 * 4) loop_count and status remain stable while lexical node persists outputs.
 */
test('lexical scout live-like flow keeps loop state stable and persists outputs', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate CBT-I versus pharmacotherapy in chronic insomnia',
        'theme_context' => 'Sleep medicine',
        'status' => 'running',
        'state_data' => [
            'loop_count' => 2,
            'protocol_draft' => [
                'scope' => [
                    'definition' => 'Comparative studies on CBT-I and pharmacotherapy in chronic insomnia.',
                    'rationale' => 'Supports treatment selection decisions.',
                ],
                'inclusion' => [
                    [
                        'criterion' => 'Adults diagnosed with chronic insomnia',
                        'rationale' => 'Clinical relevance for target cohort.',
                    ],
                ],
            ],
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);
    Queue::fake();

    $nexusClient = Mockery::mock(NexusApiClient::class);
    $nexusClient->shouldReceive('searchLiterature')
        ->once()
        ->withArgs(function (array $pico): bool {
            return ($pico['population'] ?? null) === 'Adults with chronic insomnia'
                && ($pico['intervention'] ?? null) === 'CBT-I';
        })
        ->andReturn([
            [
                'title' => 'CBT-I comparative outcomes in insomnia',
                'abstract' => 'Compares behavioral therapy and pharmacologic interventions.',
            ],
        ]);
    app()->instance(NexusApiClient::class, $nexusClient);

    Ai::fakeAgent(AnonymousAgent::class, function (string $prompt) {
        if (str_contains($prompt, 'Extract the PICO elements from the above protocol information.')) {
            return json_encode([
                'population' => 'Adults with chronic insomnia',
                'intervention' => 'CBT-I',
                'comparison' => 'Pharmacotherapy',
                'outcome' => 'Sleep efficiency',
            ], JSON_THROW_ON_ERROR);
        }

        expect($prompt)->toContain('CBT-I comparative outcomes in insomnia');

        return json_encode([
            'expanded_taxonomy' => [
                'CBT-I' => ['cognitive behavioral therapy for insomnia'],
                'Pharmacotherapy' => ['drug therapy', 'medication'],
            ],
        ], JSON_THROW_ON_ERROR);
    })->preventStrayPrompts();

    (new LexicalScoutJob($thread))->handle();

    $thread->refresh();

    expect($thread->status)->toBe(\App\Enums\ThreadStatus::Running);
    expect($thread->state_data['loop_count'] ?? null)->toBe(2);
    expect($thread->state_data['pico_framework']['comparison'] ?? null)->toBe('Pharmacotherapy');
    expect($thread->state_data['expanded_taxonomy']['CBT-I'][0] ?? null)
        ->toBe('cognitive behavioral therapy for insomnia');

    Queue::assertPushed(GenerateQueriesJob::class, function (GenerateQueriesJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});
