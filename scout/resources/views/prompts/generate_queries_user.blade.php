Draft Protocol:
Scope: {{ $scope }}
Inclusion Criteria: {{ $inclusion }}
Exclusion Criteria: {{ $exclusion }}

Expanded Taxonomy (Real Database Synonyms):
{{ json_encode($taxonomy, JSON_PRETTY_PRINT) }}

@if($critique)
Diagnostic Critique (from previous failed iteration):
{{ $critique }}

Please adjust the queries to address the above critique, broadening the scope or including missing synonyms to ensure the Golden Seeds are captured.
@endif

Generate the optimized boolean search strings based on the protocol, taxonomy, and any feedback provided.