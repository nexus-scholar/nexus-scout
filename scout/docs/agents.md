# Scout Agent Architecture

This document describes the agentic workflow of the Scout research platform. Instead of monolithic classes, "agents" are implemented as discrete **Jobs** acting as nodes in a state-machine pipeline.

## Overview of the Workflow

1. **Clarify Intent** (`ClarifyIntentJob`) -> *User Interview*
2. **Draft Protocol** (`DraftProtocolParametersJob`)
3. **Lexical Scout** (`LexicalScoutJob`)
4. **Generate Queries** (`GenerateQueriesJob`)
5. **Validate Protocol** (`ValidateProtocolJob`) -> *Conditional Loop back to Critique*
6. **Diagnostic Critique** (`DiagnosticCritiqueJob`) -> *Back to Validate*
7. **Generate Export** (`GenerateExportNodeJob`) -> *Final Result*

---

## Agent Nodes

### 1. Clarify Intent Agent (`ClarifyIntentJob`)
Analyzes the initial research objective to identify ambiguities and generate clarifying questions for the user.

- **Input:** `objective`, `theme_context` (from `Thread`).
- **Processing:** Calls AI to generate a list of structured questions (multiple choice, boolean, or open-ended).
- **Output:** Updates `Thread` with `questions`, `theme_context`, and sets status to `interviewing`.
- **Next Step:** Waits for user input (answers).

### 2. Draft Protocol Agent (`DraftProtocolParametersJob`)
Synthesizes the objective and the user's answers into a formal research protocol draft.

- **Input:** `objective`, `theme_context`, `answers` (user provided).
- **Processing:** Generates scope definitions, inclusion/exclusion criteria, and rationale.
- **Output:** Updates `state_data['protocol_draft']`.
- **Next Step:** `LexicalScoutJob`.

### 3. Lexical Scout Agent (`LexicalScoutJob`)
Performs domain-specific term expansion and PICO framework extraction.

- **Input:** `objective`, `protocol_draft`.
- **Processing:** 
    - **Phase 1:** Extracts PICO (Population, Intervention, Comparison, Outcome).
    - **Phase 2:** Calls the Python **Nexus API** (`/api/v1/scout/literature-search`) to fetch papers from OpenAlex and Semantic Scholar.
    - **Phase 3:** Summarizes a taxonomy of related terms/keywords.
- **Output:** Updates `state_data['pico_framework']` and `state_data['expanded_taxonomy']`.
- **Next Step:** `GenerateQueriesJob`.

---

## External Integrations

### Python Nexus API (`nexus-api`)

The `LexicalScoutJob` relies on a Python backend to perform federated literature searches.

**Endpoint:** `POST /api/v1/scout/literature-search`

**Request Body:**
```json
{
  "pico": {
    "population": "...",
    "intervention": "...",
    "comparison": "...",
    "outcome": "..."
  },
  "providers": ["openalex", "semantic_scholar"],
  "limit": 15
}
```

**Expected Response Body:**
```json
{
  "status": "success",
  "data": [
    {
      "title": "...",
      "abstract": "...",
      "doi": "...",
      "publication_year": 2024
    }
  ]
}
```

### 4. Generate Queries Agent (`GenerateQueriesJob`)
Constructs complex boolean search strings based on the protocol and expanded taxonomy.

- **Input:** `protocol_draft`, `expanded_taxonomy`.
- **Processing:** Group keywords into themes and generates multiple boolean query strings.
- **Output:** Updates `state_data['query_themes']` and `state_data['boolean_strings']`.
- **Next Step:** `ValidateProtocolJob`.

### 5. Validate Protocol Agent (`ValidateProtocolJob`)
Tests the generated queries against "golden seed" papers or quality thresholds.

- **Input:** `state_data['loop_count']`.
- **Processing:** Simulates validation results. 
    - If `loop_count > 0`, it passes.
    - If `loop_count == 0`, it fails to trigger the critique loop.
- **Output:** 
    - **Pass:** Sets `state_data['validation_passed'] = true`.
    - **Fail:** Increments `loop_count`, sets status to `clarification_pending`.
- **Next Step:** `GenerateExportNodeJob` (on Pass) or `DiagnosticCritiqueJob` (on Fail).

### 6. Diagnostic Critique Agent (`DiagnosticCritiqueJob`)
Analyzes why validation failed and proposes improvements to the search strategy.

- **Input:** `query_themes`, `missing_seeds`.
- **Processing:** AI analyzes missing papers and rebuilds/broadens the query themes.
- **Output:** Updates `state_data['critique']` and rebuilds `boolean_strings`.
- **Next Step:** `ValidateProtocolJob` (to re-test).

### 7. Generate Export Agent (`GenerateExportNodeJob`)
Formats the final research protocol into various machine-readable formats.

- **Input:** `Thread` and accumulated `state_data`.
- **Processing:** Generates `nexus_yaml`, `queries_yml`, and `protocol_json`.
- **Output:** Updates `nexus_yaml`, `protocol` (JSON), and sets status to `completed`.
- **Next Step:** Workflow finished.

---

## Data Structure

All agents communicate via the `threads` table, primarily through the `state_data` JSON column.

### Common `state_data` Keys:
- `protocol_draft`: The structured inclusion/exclusion criteria.
- `pico_framework`: Parsed PICO fields.
- `expanded_taxonomy`: Map of keyword expansions.
- `query_themes`: Grouped boolean search queries.
- `loop_count`: Tracking iterations through the critique loop.

## Events & Broadcasting

Each agent node broadcasts events for frontend tracking:
- `AgentNodeStarted`: Triggered at the beginning of `handle()`.
- `AgentNodeCompleted`: Triggered when the node finishes its work.
- `AgentFailed`: Triggered if an agent hits a terminal error or max loops.
- `AgentWorkflowFinished`: Triggered when the entire pipeline is done.
