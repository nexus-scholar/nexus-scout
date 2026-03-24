<?php

namespace App\Jobs;

use App\Ai\Agents\IntentClarifier;
use App\Ai\ClarifyIntentOutputValidator;
use App\Enums\ThreadStatus;
use App\Events\AgentFailed;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class ClarifyIntentJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int[]
     */
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Thread $thread
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        broadcast(new AgentNodeStarted($this->thread->id, 'clarify_intent'));

        try {
            $agent = new IntentClarifier($this->thread, IntentClarifier::PHASE_QUESTIONS);
            $response = $agent->forUser($this->thread->user)
                ->prompt($agent->getUserPrompt(), timeout: 120);

            $validated = app(ClarifyIntentOutputValidator::class)->validate($response->toArray());

            // Persist structured data
            $this->persistValidatedData($validated);

            // Record interaction metadata
            $this->thread->recordAgentInteraction(
                agentName: 'intent_clarifier_questions',
                conversationId: $response->conversationId,
                input: $agent->getUserPrompt(),
                output: $validated
            );

            broadcast(new AgentNodeCompleted($this->thread->id, 'clarify_intent', [
                'questions' => $this->thread->questions,
                'theme_context' => $this->thread->theme_context,
            ]));
        } catch (ValidationException $e) {
            $this->handlePermanentFailure('Validation failed for AI output.', $e);
        } catch (RateLimitedException|ProviderOverloadedException|FailoverableException $e) {
            throw $e; // Trigger retry
        } catch (AiException $e) {
            $this->handlePermanentFailure('AI provider error.', $e);
        } catch (Throwable $e) {
            $this->handlePermanentFailure('Unexpected error during intent clarification.', $e);
        }
    }

    /**
     * Handle a permanent failure that should not be retried.
     */
    protected function handlePermanentFailure(string $reason, Throwable $e): void
    {
        Log::error($reason, [
            'thread_id' => $this->thread->id,
            'exception' => $e->getMessage(),
        ]);

        broadcast(new AgentFailed($this->thread, $reason));

        // Still broadcast completion to stop UI loaders, but with current state.
        broadcast(new AgentNodeCompleted($this->thread->id, 'clarify_intent', [
            'questions' => $this->thread->questions,
            'theme_context' => $this->thread->theme_context,
        ]));
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        if ($this->thread->exists) {
            broadcast(new AgentFailed($this->thread, 'Job failed after maximum attempts.'));
        }
    }

    protected function persistValidatedData(array $validated): void
    {
        DB::transaction(function () use ($validated): void {
            $this->applyValidatedData($validated);
        });
    }

    protected function applyValidatedData(array $validated): void
    {
        $this->thread->update([
            'theme_context' => $validated['theme_context'],
            'status' => ThreadStatus::Interviewing,
        ]);
    }
}
