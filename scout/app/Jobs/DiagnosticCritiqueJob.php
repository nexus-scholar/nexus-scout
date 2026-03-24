<?php

namespace App\Jobs;

use App\Ai\Agents\DiagnosticCritiquor;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiagnosticCritiqueJob implements ShouldQueue
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
        broadcast(new AgentNodeStarted($this->thread->id, 'diagnostic_critique'));

        try {
            $agent = new DiagnosticCritiquor($this->thread);
            $response = $agent->forUser($this->thread->user)
                ->prompt($agent->getUserPrompt(), timeout: 120);

            $data = $response->toArray();

            if ($data) {
                $stateData = $this->thread->state_data ?? [];
                $stateData['critique'] = $data['critique'] ?? 'Analyzed missing seeds and broadening query structure.';
                $stateData['query_themes'] = $data['themes'] ?? $stateData['query_themes'] ?? [];

                $this->thread->update(['state_data' => $stateData]);

                // Record interaction metadata
                $this->thread->recordAgentInteraction(
                    agentName: 'diagnostic_critiquor',
                    conversationId: $response->conversationId,
                    input: $agent->getUserPrompt(),
                    output: $data
                );
            }

            broadcast(new AgentNodeCompleted($this->thread->id, 'diagnostic_critique', [
                'critique' => $stateData['critique'] ?? 'Queries rebuilt.',
            ]));

            \App\Services\WorkflowOrchestrator::dispatchNext($this->thread, 'diagnostic_critique');
        } catch (Throwable $e) {
            Log::error('DiagnosticCritiqueJob failed.', [
                'thread_id' => $this->thread->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
