<?php

namespace App\Jobs;

use App\Ai\ClarifyIntentOutputValidator;
use App\Ai\PromptManager;
use App\Events\AgentNodeCompleted;
use App\Events\AgentNodeStarted;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use JsonException;
use Illuminate\Validation\ValidationException;

class ClarifyIntentJob implements ShouldQueue
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
        broadcast(new AgentNodeStarted($this->thread->id, 'clarify_intent'));

        $prompts = PromptManager::getPrompts('clarify_intent', [
            'objective' => $this->thread->objective,
            'theme_context' => $this->thread->theme_context,
        ]);

        $response = \Laravel\Ai\agent($prompts['system'])
            ->prompt($prompts['user'], timeout: 120);

        try {
            $data = $this->decodeAiPayload($response->text);

            if ($data !== null) {
                $validated = app(ClarifyIntentOutputValidator::class)->validate($data);

                $this->persistValidatedData($validated);
            }
        } catch (JsonException|ValidationException) {
            // Invalid or malformed model output is ignored to keep thread state unchanged.
        }

        broadcast(new AgentNodeCompleted($this->thread->id, 'clarify_intent', [
            'questions' => $this->thread->questions,
            'theme_context' => $this->thread->theme_context,
        ]));
    }

    protected function decodeAiPayload(string $text): ?array
    {
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches)) {
            $text = $matches[1];
        }

        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    protected function persistValidatedData(array $validated): void
    {
        DB::transaction(function () use ($validated): void {
            $this->applyValidatedData($validated);
        });
    }

    protected function applyValidatedData(array $validated): void
    {
        $this->thread->update([
            'questions' => $validated['questions'],
            'theme_context' => $validated['theme_context'],
            'status' => 'interviewing',
        ]);
    }
}
