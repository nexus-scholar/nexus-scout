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
     * @return array{system: string, user: string}
     */
    public static function getPrompts(string $jobName, array $data = []): array
    {
        $systemView = "prompts.{$jobName}_system";
        $userView = "prompts.{$jobName}_user";

        return [
            'system' => View::make($systemView, $data)->render(),
            'user' => View::make($userView, $data)->render(),
        ];
    }
}
