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

class IntentClarifier implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    public const PHASE_QUESTIONS = 'questions';

    public const PHASE_REFINE = 'refine';

    private array $prompts;

    public function __construct(
        protected Thread $thread,
        protected string $phase = self::PHASE_QUESTIONS
    ) {
        $this->prompts = PromptManager::getPrompts('clarify_intent', [
            'objective' => $this->thread->objective,
            'theme_context' => $this->thread->theme_context,
            'phase' => $this->phase,
            'answers' => $this->thread->answers ?? [],
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
        if ($this->phase === self::PHASE_REFINE) {
            return [
                'refined_brief' => $schema->object([
                    'final_objective' => $schema->string()->required(),
                    'domain_context' => $schema->string()->required(),
                    'pico_elements' => $schema->object([
                        'population' => $schema->string()->nullable(),
                        'intervention' => $schema->string()->nullable(),
                        'comparison' => $schema->string()->nullable(),
                        'outcome' => $schema->string()->nullable(),
                    ])->required(),
                    'search_constraints' => $schema->array()->items($schema->string())->required(),
                ])->required(),
            ];
        }

        return [
            'theme_context' => $schema->string()
                ->description('A concise summary of the research theme and context.')
                ->required(),
            'questions' => $schema->array()
                ->items(
                    $schema->object([
                        'id' => $schema->string()->required(),
                        'type' => $schema->string()->enum($this->allowedTypes())->required(),
                        'text' => $schema->string()->required(),
                        'rationale' => $schema->string()->required(),
                        'options' => $schema->array()->items($schema->string())->nullable(),
                        'scale_range' => $schema->array()->items($schema->integer())->min(2)->max(2)->nullable(),
                    ])
                )
                ->min(1)
                ->required(),
        ];
    }

    /**
     * Get the user prompt.
     */
    public function getUserPrompt(): string
    {
        return $this->prompts['user'];
    }

    /**
     * Get the allowed question types.
     *
     * @return array<int, string>
     */
    protected function allowedTypes(): array
    {
        return ['boolean', 'multiple_choice', 'multi_select', 'scale', 'open_ended'];
    }
}
