Draft Protocol:
Scope: {{ $scope }}
Inclusion Criteria: {{ $inclusion }}
Exclusion Criteria: {{ $exclusion }}

Expanded Taxonomy (Real Database Synonyms):
{{ json_encode($taxonomy, JSON_PRETTY_PRINT) }}

Generate the optimized boolean search strings based on the protocol and taxonomy.
