<?php

namespace App\Jobs;

use App\Enums\ThreadStatus;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Events\AgentWorkflowFinished;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateExportNodeJob implements ShouldQueue
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
        broadcast(new AgentNodeStarted($this->thread->id, 'generate_export'));

        // Simulate formatting agent's work
        sleep(2);

        $stateData = $this->thread->state_data ?? [];

        $allQueries = collect($stateData['boolean_strings'] ?? [])->map(fn ($q) => "    - \"$q\"")->implode("\n");

        $exportYaml = "project:\n  id: {$this->thread->id}\n  objective: \"{$this->thread->objective}\"\n".
                     "agent_config:\n  engine: gpt-4-turbo\n  nodes:\n    - scout\n    - synthesizer\n    - validator\n".
                     "protocol:\n  date_range: \"2015-2024\"\n  sources:\n    - OpenAlex\n    - PubMed\n".
                     "  boolean_queries:\n".$allQueries;

        $protocol = [
            'protocol_draft' => $stateData['protocol_draft'] ?? [],
            'pico' => $stateData['pico_framework'] ?? [],
            'taxonomy' => $stateData['expanded_taxonomy'] ?? [],
            'query_themes' => $stateData['query_themes'] ?? [],
        ];

        $this->thread->update([
            'export_yaml' => $exportYaml,
            'protocol' => $protocol,
            'status' => ThreadStatus::Completed,
        ]);

        broadcast(new AgentNodeCompleted($this->thread->id, 'generate_export', ['payloads' => array_keys($protocol)]));
        broadcast(new AgentWorkflowFinished($this->thread, ['yaml' => $exportYaml]));
    }
}
