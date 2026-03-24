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

class FrameworkExtractor implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    private array $prompts;
    private string $type;

    public function __construct(protected Thread $thread)
    {
        $stateData = $this->thread->state_data ?? [];
        $protocol = $stateData['protocol_draft'] ?? [];

        $this->type = $this->thread->template_type === \App\Enums\TemplateType::Scoping ? 'pcc' : 'pico';

        $this->prompts = PromptManager::getPrompts('extract_framework', [
            'type' => $this->type,
            'objective' => $this->thread->objective,
            'scope' => $protocol['scope']['definition'] ?? '',
            'inclusion' => collect($protocol['inclusion'] ?? [])->pluck('criterion')->implode(', '),
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
        if ($this->type === 'pcc') {
            return [
                'population' => $schema->string()->required(),
                'concept' => $schema->string()->required(),
                'context' => $schema->string()->required(),
            ];
        }

        return [
            'population' => $schema->string()->required(),
            'intervention' => $schema->string()->required(),
            'comparison' => $schema->string()->required(),
            'outcome' => $schema->string()->required(),
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
