<?php

namespace App\Services;

use App\Enums\TemplateType;
use App\Jobs\DiagnosticCritiqueJob;
use App\Jobs\DraftProtocolParametersJob;
use App\Jobs\GenerateExportNodeJob;
use App\Jobs\GenerateQueriesJob;
use App\Jobs\LexicalScoutJob;
use App\Jobs\ValidateProtocolJob;
use App\Models\Thread;

class WorkflowOrchestrator
{
    /**
     * Determine and dispatch the next job in the workflow.
     */
    public static function dispatchNext(Thread $thread, string $completedNode, array $context = []): void
    {
        $nextJob = match ($thread->template_type) {
            TemplateType::SLR => self::slrPipeline($thread, $completedNode, $context),
            TemplateType::Scoping => self::scopingPipeline($thread, $completedNode, $context),
            TemplateType::Rapid => self::rapidPipeline($thread, $completedNode, $context),
            TemplateType::RelatedWorks => self::relatedWorksPipeline($thread, $completedNode, $context),
        };

        if ($nextJob) {
            dispatch($nextJob);
        }
    }

    protected static function slrPipeline(Thread $thread, string $completedNode, array $context = []): ?object
    {
        return match ($completedNode) {
            'clarify_intent' => null, // Waits for user input
            'refine_intent' => new DraftProtocolParametersJob($thread),
            'draft_protocol' => new LexicalScoutJob($thread),
            'lexical_scout' => new GenerateQueriesJob($thread),
            'generate_queries' => new ValidateProtocolJob($thread),
            'validate_protocol' => ($context['passed'] ?? false)
                ? new GenerateExportNodeJob($thread)
                : new DiagnosticCritiqueJob($thread),
            'diagnostic_critique' => new GenerateQueriesJob($thread),
            'generate_export' => null, // Finished
            default => null,
        };
    }

    protected static function scopingPipeline(Thread $thread, string $completedNode, array $context = []): ?object
    {
        // Scoping review uses the same pipeline as SLR for now, but agents will behave differently.
        return self::slrPipeline($thread, $completedNode, $context);
    }

    protected static function rapidPipeline(Thread $thread, string $completedNode, array $context = []): ?object
    {
        // To be implemented
        return null;
    }

    protected static function relatedWorksPipeline(Thread $thread, string $completedNode, array $context = []): ?object
    {
        // To be implemented
        return null;
    }
}
