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
        'user_id',
        'objective',
        'theme_context',
        'status',
        'questions',
        'answers',
        'nexus_yaml',
        'protocol',
        'state_data',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

/**
 * This test verifies relationship and cast behavior for persisted thread data.
 */
test('thread belongs to user and casts json attributes to arrays', function () {
    $user = User::factory()->create();

    $thread = $user->threads()->create([
        'objective' => 'Evaluate CBT outcomes in chronic insomnia',
        'theme_context' => 'Sleep medicine',
        'questions' => [['id' => 'q1', 'text' => 'What population?']],
        'answers' => ['q1' => 'Adults'],
        'protocol' => ['scope' => ['definition' => 'Adult insomnia trials']],
        'state_data' => ['loop_count' => 1],
    ]);

    $thread->refresh();

    expect($thread->user->is($user))->toBeTrue();
    expect($thread->questions)->toBeArray();
    expect($thread->answers)->toBeArray();
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
    expect($thread->isFillable('user_id'))->toBeFalse();
});
