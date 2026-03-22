<?php

namespace App\Jobs;

use App\Events\AgentFailed;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Events\AgentWorkflowFinished;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateProtocolJob implements ShouldQueue
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
        broadcast(new AgentNodeStarted($this->thread->id, 'validate_protocol'));

        // Simulate validation testing
        sleep(2);

        $stateData = $this->thread->state_data ?? [];
        $loopCount = $stateData['loop_count'] ?? 0;
        
        // Simulate failure on first pass to trigger DiagnosticCritiqueJob
        $validationPassed = $loopCount > 0;

        if ($validationPassed) {
            $stateData['validation_passed'] = true;
            $this->thread->update(['state_data' => $stateData]);
            
            broadcast(new AgentNodeCompleted($this->thread->id, 'validate_protocol', ['status' => 'passed']));
            dispatch(new GenerateExportNodeJob($this->thread));
        } else {
            broadcast(new AgentNodeCompleted($this->thread->id, 'validate_protocol', ['status' => 'failed', 'reason' => 'Query missed golden seeds']));

            if ($loopCount >= 3) {
                $this->thread->update(['status' => 'failed']);
                broadcast(new AgentFailed($this->thread, "Max loops reached during validation"));
                return;
            }
            
            $stateData['loop_count'] = $loopCount + 1;
            $this->thread->update(['state_data' => $stateData]);
            
            dispatch(new DiagnosticCritiqueJob($this->thread));
        }
    }
}
