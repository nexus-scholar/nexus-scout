<?php

use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Jobs\RunSearchNodeJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * This test verifies run_search orchestration behavior:
 * 1) emits node start event.
 * 2) emits completion event with expected status payload.
 * 3) does not mutate persisted thread fields.
 */
test('run search node job emits lifecycle events and completion status', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Assess SSRI outcomes in pediatric CFS',
        'theme_context' => 'Child psychiatry',
        'status' => 'executing',
        'state_data' => [
            'query_themes' => [
                ['name' => 'Theme A', 'queries' => []],
            ],
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
    ]);

    (new RunSearchNodeJob($thread))->handle();

    $thread->refresh();

    expect($thread->status)->toBe(\App\Enums\ThreadStatus::Executing);

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'run_search';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'run_search'
            && ($event->discoveries['status'] ?? null) === 'search_completed';
    });
});
