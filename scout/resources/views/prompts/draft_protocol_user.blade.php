Objective: {{ $objective }}
Theme Context: {{ $theme_context }}

User Clarifications:
@foreach($clarifications as $item)
Q: {{ $item['question_id'] ?? 'Unknown' }}
A: {{ $item['answer'] ?? 'N/A' }}
@endforeach

Draft the formal Systematic Review Protocol based on this input.
