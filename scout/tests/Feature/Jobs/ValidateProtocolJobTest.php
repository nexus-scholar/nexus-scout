<?php

use App\Events\AgentFailed;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Jobs\DiagnosticCritiqueJob;
use App\Jobs\GenerateExportNodeJob;
use App\Jobs\ValidateProtocolJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * This test verifies the validation pass branch:
 * 1) loop_count > 0 marks validation as passed.
 * 2) state_data.validation_passed is persisted.
 * 3) completion event reports passed status.
 * 4) pipeline dispatches GenerateExportNodeJob.
 */
test('validate protocol job marks passed and dispatches generate export when loop count is above zero', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate behavioral interventions in chronic insomnia',
        'theme_context' => 'Sleep medicine',
        'state_data' => [
            'loop_count' => 1,
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
        AgentFailed::class,
    ]);
    Queue::fake();

    (new ValidateProtocolJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data['validation_passed'] ?? null)->toBeTrue();

    Event::assertDispatched(AgentNodeStarted::class, function (AgentNodeStarted $event) use ($thread): bool {
        return $event->threadId === $thread->id && $event->node === 'validate_protocol';
    });

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'validate_protocol'
            && ($event->discoveries['status'] ?? null) === 'passed';
    });

    Event::assertNotDispatched(AgentFailed::class);

    Queue::assertPushed(GenerateExportNodeJob::class, function (GenerateExportNodeJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });

    Queue::assertNotPushed(DiagnosticCritiqueJob::class);
});

/**
 * This test verifies the retry branch:
 * 1) loop_count = 0 yields failed validation.
 * 2) state_data.loop_count increments by one.
 * 3) completion event reports failed status and reason.
 * 4) pipeline dispatches DiagnosticCritiqueJob.
 */
test('validate protocol job increments loop and dispatches diagnostic critique on first failure', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate SSRI efficacy in adolescent depression',
        'theme_context' => 'Child psychiatry',
        'state_data' => [
            'loop_count' => 0,
        ],
    ]);

    Event::fake([
        AgentNodeStarted::class,
        AgentNodeCompleted::class,
        AgentFailed::class,
    ]);
    Queue::fake();

    (new ValidateProtocolJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data['loop_count'] ?? null)->toBe(1);
    expect($thread->status)->toBe(\App\Enums\ThreadStatus::ClarificationPending);

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'validate_protocol'
            && ($event->discoveries['status'] ?? null) === 'failed'
            && ($event->discoveries['reason'] ?? null) === 'Query missed golden seeds';
    });

    Event::assertNotDispatched(AgentFailed::class);

    Queue::assertPushed(DiagnosticCritiqueJob::class, function (DiagnosticCritiqueJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });

    Queue::assertNotPushed(GenerateExportNodeJob::class);
});

/**
 * This edge-case test documents current production behavior:
 * loop_count >= 3 still takes the "passed" branch because pass/fail is derived
 * from loop_count > 0 before max-loop handling is evaluated.
 */
test('validate protocol job currently passes when loop count is three and does not emit agent failed', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Assess treatment effects in severe chronic fatigue',
        'theme_context' => 'Internal medicine',
        'state_data' => [
            'loop_count' => 3,
        ],
    ]);

    Event::fake([
        AgentNodeCompleted::class,
        AgentFailed::class,
    ]);
    Queue::fake();

    (new ValidateProtocolJob($thread))->handle();

    $thread->refresh();

    expect($thread->state_data['validation_passed'] ?? null)->toBeTrue();
    expect($thread->status)->toBe(\App\Enums\ThreadStatus::ClarificationPending);

    Event::assertDispatched(AgentNodeCompleted::class, function (AgentNodeCompleted $event) use ($thread): bool {
        return $event->threadId === $thread->id
            && $event->node === 'validate_protocol'
            && ($event->discoveries['status'] ?? null) === 'passed';
    });

    Event::assertNotDispatched(AgentFailed::class);

    Queue::assertPushed(GenerateExportNodeJob::class, function (GenerateExportNodeJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});
