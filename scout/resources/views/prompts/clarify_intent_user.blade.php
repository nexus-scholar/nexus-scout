@if($phase === 'questions')
Objective: {{ $objective }}
Existing Theme: {{ $theme_context ?? 'None' }}

Please analyze the above objective and provide the theme context and clarifying questions.
@elseif($phase === 'refine')
Based on our previous interaction and the user's provided answers, please generate the final refined research brief.

User Answers:
@foreach($answers as $id => $answer)
- Question ID {{ $id }}: {{ is_array($answer) ? implode(', ', $answer) : $answer }}
@endforeach
@endif
