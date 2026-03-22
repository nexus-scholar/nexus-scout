<?php

namespace App\Jobs;

use App\Ai\PromptManager;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Ai;

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

        $prompts = PromptManager::getPrompts('draft_protocol', [
            'objective' => $this->thread->objective,
            'theme_context' => $this->thread->theme_context,
            'clarifications' => $this->thread->answers ?? [],
        ]);

        $response = \Laravel\Ai\agent($prompts['system'])
            ->prompt($prompts['user'], timeout: 120);

        $text = $response->text;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches)) {
            $text = $matches[1];
        }
        $data = json_decode($text, true);

        if ($data) {
            $stateData = $this->thread->state_data ?? [];
            $stateData['protocol_draft'] = $data;
            
            $this->thread->update(['state_data' => $stateData]);
        }

        broadcast(new AgentNodeCompleted($this->thread->id, 'draft_protocol', [
            'protocol' => $this->thread->state_data['protocol_draft'] ?? []
        ]));

        dispatch(new LexicalScoutJob($this->thread));
    }
}
