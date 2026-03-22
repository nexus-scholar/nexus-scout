<?php

namespace App\Jobs;

use App\Ai\PromptManager;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Ai;

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

        $stateData = $this->thread->state_data ?? [];
        $protocol = $stateData['protocol_draft'] ?? [];

        $prompts = PromptManager::getPrompts('generate_queries', [
            'scope' => $protocol['scope']['definition'] ?? '',
            'inclusion' => collect($protocol['inclusion'] ?? [])->pluck('criterion')->implode(', '),
            'exclusion' => collect($protocol['exclusion'] ?? [])->pluck('criterion')->implode(', '),
            'taxonomy' => $stateData['expanded_taxonomy'] ?? [],
            'critique' => $stateData['critique'] ?? null,
        ]);

        $response = \Laravel\Ai\agent($prompts['system'])
            ->prompt($prompts['user'], timeout: 120);

        $text = $response->text;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches)) {
            $text = $matches[1];
        }
        $data = json_decode($text, true);

        if ($data) {
            $stateData['query_themes'] = $data['themes'] ?? [];
            
            // Extract a flat list of boolean strings for the legacy nexus_yaml logic if needed
            $flatQueries = collect($data['themes'] ?? [])
                ->flatMap(fn($theme) => collect($theme['queries'] ?? [])->pluck('query_string'))
                ->toArray();
            
            $stateData['boolean_strings'] = $flatQueries;
            
            $this->thread->update(['state_data' => $stateData]);
        }

        broadcast(new AgentNodeCompleted($this->thread->id, 'generate_queries', [
            'themes' => $stateData['query_themes'] ?? []
        ]));

        dispatch(new ValidateProtocolJob($this->thread));
    }
}
