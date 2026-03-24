<?php

namespace App\Ai\Agents;

use App\Ai\PromptManager;
use App\Models\Thread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class DiagnosticCritiquor implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    private array $prompts;

    public function __construct(protected Thread $thread)
    {
        $stateData = $this->thread->state_data ?? [];

        $this->prompts = PromptManager::getPrompts('diagnostic_critique', [
            'queries' => $stateData['query_themes'] ?? [],
            'missing_seeds' => $stateData['missing_seeds'] ?? ['10.1001/jamapsychiatry.2023.0001'],
        ], $this->thread->template_type);
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return $this->prompts['system'];
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'critique' => $schema->string()->required(),
            'themes' => $schema->array()->items(
                $schema->object([
                    'name' => $schema->string()->required(),
                    'queries' => $schema->array()->items(
                        $schema->object([
                            'id' => $schema->string()->required(),
                            'query_string' => $schema->string()->required(),
                            'target_fields' => $schema->array()->items($schema->string())->required(),
                        ])
                    )->min(1)->required(),
                ])
            )->min(1)->required(),
        ];
    }

    /**
     * Get the user prompt.
     */
    public function getUserPrompt(): string
    {
        return $this->prompts['user'];
    }
}
