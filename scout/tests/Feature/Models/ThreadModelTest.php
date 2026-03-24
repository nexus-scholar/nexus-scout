<?php

use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * This test verifies table-level schema for threads to catch migration drift.
 */
test('threads table contains required columns', function () {
    expect(Schema::hasTable('threads'))->toBeTrue();

    expect(Schema::hasColumns('threads', [
        'id',
        'project_id',
        'template_type',
        'objective',
        'theme_context',
        'status',
        'protocol',
        'state_data',
        'export_yaml',
        'agent_interactions',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

/**
 * This test verifies relationship and cast behavior for persisted thread data.
 */
test('thread belongs to user and casts json attributes to arrays', function () {
    $user = User::factory()->create();

    $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
    $thread = $project->threads()->create([
        'template_type' => \App\Enums\TemplateType::SLR,
        'objective' => 'Evaluate CBT outcomes in chronic insomnia',
        'theme_context' => 'Sleep medicine',
        'protocol' => ['scope' => ['definition' => 'Adult insomnia trials']],
        'state_data' => ['loop_count' => 1],
    ]);

    $thread->refresh();

    expect($thread->project->user->is($user))->toBeTrue();
    expect($thread->protocol)->toBeArray();
    expect($thread->state_data)->toBeArray();
    expect($thread->state_data['loop_count'])->toBe(1);
});

/**
 * This test verifies model-level mass assignment policy from Fillable attribute.
 */
test('thread model exposes expected fillable attributes', function () {
    $thread = new Thread();

    expect($thread->isFillable('objective'))->toBeTrue();
    expect($thread->isFillable('theme_context'))->toBeTrue();
    expect($thread->isFillable('state_data'))->toBeTrue();
    expect($thread->isFillable('project_id'))->toBeTrue();
});
