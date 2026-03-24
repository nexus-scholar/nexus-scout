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

class ProtocolDrafter implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    private array $prompts;

    public function __construct(protected Thread $thread)
    {
        $stateData = $this->thread->state_data ?? [];
        $brief = $stateData['refined_brief'] ?? [];

        $this->prompts = PromptManager::getPrompts('draft_protocol', [
            'objective' => $brief['final_objective'] ?? $this->thread->objective,
            'theme_context' => $brief['domain_context'] ?? $this->thread->theme_context,
            'pico' => $brief['pico_elements'] ?? [],
            'constraints' => $brief['search_constraints'] ?? [],
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
            'scope' => $schema->object([
                'definition' => $schema->string()->required(),
                'rationale' => $schema->string()->required(),
            ])->required(),
            'inclusion' => $schema->array()->items(
                $schema->object([
                    'criterion' => $schema->string()->required(),
                    'rationale' => $schema->string()->required(),
                ])
            )->min(1)->required(),
            'exclusion' => $schema->array()->items(
                $schema->object([
                    'criterion' => $schema->string()->required(),
                    'rationale' => $schema->string()->required(),
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
