<?php

namespace App\Jobs;

use App\Ai\PromptManager;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Ai;

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

        $stateData = $this->thread->state_data ?? [];
        
        $prompts = PromptManager::getPrompts('diagnostic_critique', [
            'queries' => $stateData['query_themes'] ?? [],
            'missing_seeds' => $stateData['missing_seeds'] ?? ['10.1001/jamapsychiatry.2023.0001'],
        ]);

        $response = \Laravel\Ai\agent($prompts['system'])
            ->prompt($prompts['user'], timeout: 120);

        $text = $response->text;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches)) {
            $text = $matches[1];
        }
        $data = json_decode($text, true);

        if ($data) {
            $stateData['critique'] = $data['critique'] ?? "Analyzed missing seeds and broadening query structure.";
            
            $this->thread->update(['state_data' => $stateData]);
        }

        broadcast(new AgentNodeCompleted($this->thread->id, 'diagnostic_critique', [
            'critique' => $stateData['critique'] ?? 'Queries rebuilt.'
        ]));

        dispatch(new GenerateQueriesJob($this->thread));
    }
}
