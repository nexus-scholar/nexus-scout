# Refactoring & Optimization Plan: Scout Agent Pipeline

## 1. Problem Statement
The current implementation of the agentic research pipeline exhibits several inefficiencies:
- **Data Redundancy**: Information is duplicated across `nexus_yaml`, `protocol`, and `state_data` columns, making the `threads` table unnecessarily large and prone to synchronization issues.
- **Broken Critique Loop**: The `DiagnosticCritiqueJob` was previously failing to pass its "critique" context back to the `GenerateQueriesJob`, resulting in the same queries being generated repeatedly or empty results during the loop.
- **Output Fragmentation**: Final artifacts were scattered across multiple fields with inconsistent naming (e.g., `nexus_yaml` vs. `export_yaml`).
- **AI Hallucinations in Taxonomy**: The `LexicalScoutJob` occasionally included irrelevant domain terms (like "Machine Learning" in a "Melatonin" search) due to broad context injection.

## 2. Proposed Data Architecture

### Column-Level Responsibilities
To eliminate redundancy, we will enforce a strict "Source of Truth" policy for each column in the `threads` table:

| Column | Role | Data Type |
| :--- | :--- | :--- |
| `state_data` | **Internal Agent Memory**: Stores intermediate results, loop counts, PICO frameworks, and critiques. Not for final consumption. | `json` |
| `protocol` | **Structured JSON Export**: The final, human-readable JSON representation of the research protocol. | `json` |
| `export_yaml` | **Final YAML Artifact**: The serialized string version of the protocol, optimized for download/export. | `text` |
| `nexus_yaml` | **Deprecated**: To be phased out in favor of `export_yaml`. | `text` |

### Field Sanitization in `state_data`
- **PICO Framework**: Ensure 1:1 mapping with the research objective.
- **Expanded Taxonomy**: Filter for relevance using the `protocol_draft` as a semantic anchor.
- **Critique**: Must contain actionable feedback that influences the next query generation step.

## 3. Workflow Optimization

### The "Smart Research Loop"
We are formalizing the loop into a true state machine:

1. **Lexical Scout** -> Fetches real papers -> Summarizes Taxonomy.
2. **Generate Queries** -> Consumes Taxonomy + **(Optional) Critique**.
3. **Validate Protocol** -> Tests Query Strength.
4. **Diagnostic Critique** (If Validation Fails) -> Generates specific feedback -> **Dispatches Generate Queries**.

### Job Refactoring Details

#### A. `GenerateQueriesJob`
- **Change**: Now accepts a `critique` parameter from `state_data`.
- **Impact**: The AI will now "know" why the previous queries failed (e.g., "Too broad", "Missing the population term") and adjust the boolean logic accordingly.

#### B. `DiagnosticCritiqueJob`
- **Change**: Refactored to act as a "Feedback Agent" rather than a "Repair Agent". 
- **Impact**: It no longer tries to write the queries itself (which it was doing poorly); it provides the *critique* and asks the `GenerateQueriesJob` to try again with that new knowledge.

#### C. `GenerateExportNodeJob`
- **Change**: Consolidates all final fields from `state_data` into the `protocol` (JSON) and `export_yaml` (String) columns.
- **Impact**: Provides a single point of entry for the frontend to fetch final results.

## 4. Implementation Checklist

- [x] Create `export_yaml` migration.
- [x] Update `Thread` model fillable attributes.
- [x] Refactor `GenerateQueriesJob` to handle critique context.
- [x] Refactor `DiagnosticCritiqueJob` to dispatch query generation.
- [x] Update `GenerateExportNodeJob` to aggregate all boolean strings.
- [x] Update `live_test_script.php` to verify the optimized flow.
- [ ] Implement `ThreadObserver` (Optional) to clean up `state_data` once status is `completed`.

## 5. Expected Outcomes
- **Clean Database**: 40% reduction in per-thread storage by eliminating duplicated YAML strings.
- **Higher Query Quality**: Queries will become progressively more specific if they fail initial validation.
- **Developer Clarity**: Clear separation between "working data" (`state_data`) and "result data" (`protocol`/`export_yaml`).
