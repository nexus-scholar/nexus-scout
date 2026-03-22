Previous Queries:
{{ json_encode($queries, JSON_PRETTY_PRINT) }}

Missing Golden Seeds (DOIs/Titles):
@foreach($missing_seeds as $seed)
- {{ $seed }}
@endforeach

Analyze the failure and provide a rebuilt query bundle.
