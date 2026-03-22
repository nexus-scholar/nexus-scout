<?php

namespace App\Jobs;

use App\Ai\PromptManager;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use App\Services\NexusApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Ai;

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

        $stateData = $this->thread->state_data ?? [];
        $protocol = $stateData['protocol_draft'] ?? [];

        // --- Phase 1: PICO Extraction ---
        $picoPrompts = PromptManager::getPrompts('extract_pico', [
            'objective' => $this->thread->objective,
            'scope' => $protocol['scope']['definition'] ?? '',
            'inclusion' => collect($protocol['inclusion'] ?? [])->pluck('criterion')->implode(', '),
        ]);

        $picoResponse = \Laravel\Ai\agent($picoPrompts['system'])
            ->prompt($picoPrompts['user'], timeout: 120);

        $picoText = $picoResponse->text;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $picoText, $matches)) {
            $picoText = $matches[1];
        }
        $picoData = json_decode($picoText, true);
        $stateData['pico_framework'] = $picoData ?? [];

        // --- Phase 2: Nexus API Fetching (OpenAlex & Semantic Scholar) ---
        $nexusClient = app(NexusApiClient::class);
        $papers = $nexusClient->searchLiterature($stateData['pico_framework'], ['limit' => 15]);

        // --- Phase 3: Taxonomy Summarization ---
        $taxPrompts = PromptManager::getPrompts('summarize_taxonomy', [
            'papers' => $papers,
        ]);

        $taxResponse = \Laravel\Ai\agent($taxPrompts['system'])
            ->prompt($taxPrompts['user'], timeout: 120);

        $taxText = $taxResponse->text;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $taxText, $matches)) {
            $taxText = $matches[1];
        }
        $taxData = json_decode($taxText, true);
        $stateData['expanded_taxonomy'] = $taxData['expanded_taxonomy'] ?? [];

        $this->thread->update(['state_data' => $stateData]);

        broadcast(new AgentNodeCompleted($this->thread->id, 'lexical_scout', [
            'pico' => $stateData['pico_framework'],
            'taxonomy' => $stateData['expanded_taxonomy'],
        ]));

        dispatch(new GenerateQueriesJob($this->thread));
    }
}
