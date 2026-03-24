<?php

namespace App\Jobs;

use App\Ai\Agents\ProtocolDrafter;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DraftProtocolParametersJob implements ShouldQueue
{
    use Queueable;

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
        broadcast(new AgentNodeStarted($this->thread->id, 'draft_protocol'));

        try {
            $agent = new ProtocolDrafter($this->thread);
            $response = $agent->forUser($this->thread->user)
                ->prompt($agent->getUserPrompt(), timeout: 120);

            $data = $response->toArray();

            if ($data) {
                $stateData = $this->thread->state_data ?? [];
                $stateData['protocol_draft'] = $data;

                $this->thread->update(['state_data' => $stateData]);

                // Record interaction metadata
                $this->thread->recordAgentInteraction(
                    agentName: 'protocol_drafter',
                    conversationId: $response->conversationId,
                    input: $agent->getUserPrompt(),
                    output: $data
                );
            }

            broadcast(new AgentNodeCompleted($this->thread->id, 'draft_protocol', [
                'protocol' => $this->thread->state_data['protocol_draft'] ?? [],
            ]));

            \App\Services\WorkflowOrchestrator::dispatchNext($this->thread, 'draft_protocol');
        } catch (Throwable $e) {
            Log::error('DraftProtocolParametersJob failed.', [
                'thread_id' => $this->thread->id,
                'exception' => $e->getMessage(),
            ]);
            // Handle failure logic here if needed
        }
    }
}
