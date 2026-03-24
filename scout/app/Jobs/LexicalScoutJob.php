<?php

namespace App\Jobs;

use App\Ai\Agents\FrameworkExtractor;
use App\Ai\Agents\TaxonomySummarizer;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use App\Services\NexusApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class LexicalScoutJob implements ShouldQueue
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
        broadcast(new AgentNodeStarted($this->thread->id, 'lexical_scout'));

        try {
            $stateData = $this->thread->state_data ?? [];

            // --- Phase 1: Framework Extraction ---
            $picoAgent = new FrameworkExtractor($this->thread);
            $picoResponse = $picoAgent->forUser($this->thread->user)
                ->prompt($picoAgent->getUserPrompt(), timeout: 120);

            $picoData = $picoResponse->toArray();
            $stateData['pico_framework'] = $picoData ?? [];

            $this->thread->recordAgentInteraction(
                agentName: 'framework_extractor',
                conversationId: $picoResponse->conversationId,
                input: $picoAgent->getUserPrompt(),
                output: $picoData
            );

            // --- Phase 2: Nexus API Fetching ---
            $nexusClient = app(NexusApiClient::class);
            $papers = $nexusClient->searchLiterature($stateData['pico_framework'], ['limit' => 15]);

            // --- Phase 3: Taxonomy Summarization ---
            $taxAgent = new TaxonomySummarizer($this->thread, $papers);
            $taxResponse = $taxAgent->forUser($this->thread->user)
                ->prompt($taxAgent->getUserPrompt(), timeout: 120);

            $taxData = $taxResponse->toArray();
            $stateData['expanded_taxonomy'] = $taxData['expanded_taxonomy'] ?? [];

            $this->thread->recordAgentInteraction(
                agentName: 'taxonomy_summarizer',
                conversationId: $taxResponse->conversationId,
                input: $taxAgent->getUserPrompt(),
                output: $taxData
            );

            $this->thread->update(['state_data' => $stateData]);

            broadcast(new AgentNodeCompleted($this->thread->id, 'lexical_scout', [
                'pico' => $stateData['pico_framework'],
                'taxonomy' => $stateData['expanded_taxonomy'],
            ]));

            \App\Services\WorkflowOrchestrator::dispatchNext($this->thread, 'lexical_scout');
        } catch (Throwable $e) {
            Log::error('LexicalScoutJob failed.', [
                'thread_id' => $this->thread->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
