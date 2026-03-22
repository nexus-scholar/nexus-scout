<?php

namespace App\Ai;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClarifyIntentOutputValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{theme_context: string, questions: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validate(array $payload): array
    {
        $validator = Validator::make($payload, [
            'theme_context' => ['required', 'string'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.id' => ['required', 'string'],
            'questions.*.type' => ['required', 'string', Rule::in($this->allowedTypes())],
            'questions.*.text' => ['required', 'string'],
            'questions.*.rationale' => ['required', 'string'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.*' => ['string'],
            'questions.*.scale_range' => ['nullable', 'array', 'size:2'],
            'questions.*.scale_range.*' => ['integer'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            $questions = $payload['questions'] ?? [];

            if (! is_array($questions)) {
                return;
            }

            foreach ($questions as $index => $question) {
                if (! is_array($question)) {
                    continue;
                }

                $type = $question['type'] ?? null;
                $options = $question['options'] ?? null;
                $scaleRange = $question['scale_range'] ?? null;

                if (in_array($type, ['multiple_choice', 'multi_select'], true)) {
                    if (! is_array($options) || count($options) < 2) {
                        $validator->errors()->add("questions.{$index}.options", 'Options are required and must contain at least 2 values for choice questions.');
                    }
                } elseif (array_key_exists('options', $question)) {
                    $validator->errors()->add("questions.{$index}.options", 'Options are only allowed for multiple_choice and multi_select questions.');
                }

                if ($type === 'scale') {
                    if (! is_array($scaleRange) || count($scaleRange) !== 2) {
                        $validator->errors()->add("questions.{$index}.scale_range", 'Scale range is required for scale questions and must contain [min, max].');

                        continue;
                    }

                    [$min, $max] = $scaleRange;

                    if (! is_int($min) || ! is_int($max) || $min >= $max) {
                        $validator->errors()->add("questions.{$index}.scale_range", 'Scale range must contain two integers where min is less than max.');
                    }
                } elseif (array_key_exists('scale_range', $question)) {
                    $validator->errors()->add("questions.{$index}.scale_range", 'Scale range is only allowed for scale questions.');
                }
            }
        });

        /** @var array{theme_context: string, questions: array<int, array<string, mixed>>} $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * @return array<int, string>
     */
    protected function allowedTypes(): array
    {
        return ['boolean', 'multiple_choice', 'multi_select', 'scale', 'open_ended'];
    }
}
