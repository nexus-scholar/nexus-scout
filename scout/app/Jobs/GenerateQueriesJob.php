<?php

namespace App\Jobs;

use App\Ai\Agents\QueryGenerator;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateQueriesJob implements ShouldQueue
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
        broadcast(new AgentNodeStarted($this->thread->id, 'generate_queries'));

        try {
            $agent = new QueryGenerator($this->thread);
            $response = $agent->forUser($this->thread->user)
                ->prompt($agent->getUserPrompt(), timeout: 120);

            $data = $response->toArray();

            if ($data) {
                $stateData = $this->thread->state_data ?? [];
                $stateData['query_themes'] = $data['themes'] ?? [];

                // Extract a flat list of boolean strings for the legacy nexus_yaml logic if needed
                $flatQueries = collect($data['themes'] ?? [])
                    ->flatMap(fn ($theme) => collect($theme['queries'] ?? [])->pluck('query_string'))
                    ->toArray();

                $stateData['boolean_strings'] = $flatQueries;

                $this->thread->update(['state_data' => $stateData]);

                // Record interaction metadata
                $this->thread->recordAgentInteraction(
                    agentName: 'query_generator',
                    conversationId: $response->conversationId,
                    input: $agent->getUserPrompt(),
                    output: $data
                );
            }

            broadcast(new AgentNodeCompleted($this->thread->id, 'generate_queries', [
                'themes' => $stateData['query_themes'] ?? [],
            ]));

            \App\Services\WorkflowOrchestrator::dispatchNext($this->thread, 'generate_queries');
        } catch (Throwable $e) {
            Log::error('GenerateQueriesJob failed.', [
                'thread_id' => $this->thread->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
