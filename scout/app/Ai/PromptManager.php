<?php

namespace App\Ai;

use Illuminate\Support\Facades\View;

class PromptManager
{
    /**
     * Get the system and user prompts for a specific job.
     *
     * @param string $jobName
     * @param array $data
     * @param \App\Enums\TemplateType|null $templateType
     * @return array{system: string, user: string}
     */
    public static function getPrompts(string $jobName, array $data = [], ?\App\Enums\TemplateType $templateType = null): array
    {
        $systemView = "prompts.{$jobName}_system";
        $userView = "prompts.{$jobName}_user";

        $data['template_type'] = $templateType?->value;

        return [
            'system' => View::make($systemView, $data)->render(),
            'user' => View::make($userView, $data)->render(),
        ];
    }
}
