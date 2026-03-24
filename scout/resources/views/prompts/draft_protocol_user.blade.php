Objective: {{ $objective }}
Domain Context: {{ $theme_context }}

PICO Elements:
@if(!empty($pico))
- Population: {{ $pico['population'] ?? 'N/A' }}
- Intervention: {{ $pico['intervention'] ?? 'N/A' }}
- Comparison: {{ $pico['comparison'] ?? 'N/A' }}
- Outcome: {{ $pico['outcome'] ?? 'N/A' }}
@else
N/A
@endif

Search Constraints:
@if(!empty($constraints))
@foreach($constraints as $constraint)
- {{ $constraint }}
@endforeach
@else
None
@endif

Draft the formal Systematic Review Protocol based on this structured input.