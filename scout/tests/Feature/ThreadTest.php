<?php

use App\Jobs\ClarifyIntentJob;
use App\Models\User;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('authenticated user can view projects index', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('projects.index'));

    $response->assertStatus(200);
});

test('authenticated user can view project creation page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('projects.create'));

    $response->assertStatus(200);
});

test('authenticated user can create a project', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withoutMiddleware()
        ->post(route('projects.store'), [
            'objective' => 'Research SSRIs on CFS',
            'theme_context' => 'Clinical',
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();
    $thread = Thread::first();
    expect($thread->objective)->toBe('Research SSRIs on CFS');
    $response->assertRedirect(route('projects.interview', $thread));

    Queue::assertPushed(ClarifyIntentJob::class);
});

test('creating a project dispatches clarify intent job with the created thread', function () {
    // Arrange: fake queue so we can assert dispatch payload without running the job.
    Queue::fake();

    $user = User::factory()->create();

    // Act: create a project through the same HTTP entry point used by the app.
    $response = $this->actingAs($user)
        ->withoutMiddleware()
        ->post(route('projects.store'), [
            'objective' => 'Identify first-line treatment outcomes for generalized anxiety disorder',
            'theme_context' => 'Psychiatry',
        ]);

    $thread = Thread::query()->latest('created_at')->first();

    // Assert: request succeeds and dispatches ClarifyIntentJob for the newly created thread.
    $response->assertRedirect(route('projects.interview', $thread));

    Queue::assertPushed(ClarifyIntentJob::class, function (ClarifyIntentJob $job) use ($thread): bool {
        return $job->thread->is($thread);
    });
});
