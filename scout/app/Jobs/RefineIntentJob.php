<?php

namespace App\Jobs;

use App\Ai\Agents\IntentClarifier;
use App\Enums\ThreadStatus;
use App\Events\AgentFailed;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RefineIntentJob implements ShouldQueue
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
        broadcast(new AgentNodeStarted($this->thread->id, 'refine_intent'));

        try {
            $agent = new IntentClarifier($this->thread, IntentClarifier::PHASE_REFINE);

            $conversationId = $this->thread->getConversationId('intent_clarifier_questions');

            if (! $conversationId) {
                throw new RuntimeException('No existing conversation found for refinement.');
            }

            // Continue the existing conversation
            $response = $agent->continue($conversationId, as: $this->thread->user)
                ->prompt($agent->getUserPrompt(), timeout: 120);

            $data = $response->toArray();

            if (isset($data['refined_brief'])) {
                $stateData = $this->thread->state_data ?? [];
                $stateData['refined_brief'] = $data['refined_brief'];

                $this->thread->update([
                    'state_data' => $stateData,
                    'status' => ThreadStatus::Running,
                ]);

                // Record interaction metadata
                $this->thread->recordAgentInteraction(
                    agentName: 'intent_clarifier_refine',
                    conversationId: $response->conversationId,
                    input: $agent->getUserPrompt(),
                    output: $data
                );
            }

            broadcast(new AgentNodeCompleted($this->thread->id, 'refine_intent', [
                'brief' => $this->thread->state_data['refined_brief'] ?? [],
            ]));

            \App\Services\WorkflowOrchestrator::dispatchNext($this->thread, 'refine_intent');
        } catch (Throwable $e) {
            Log::error('RefineIntentJob failed.', [
                'thread_id' => $this->thread->id,
                'exception' => $e->getMessage(),
            ]);

            broadcast(new AgentFailed($this->thread, 'Failed to refine research intent.'));
        }
    }
}
