# Scout Multi-Agent Workflow Architecture

This document maps out the complete sequential flow, agent handoffs, and feedback loops within the Scout application.

```mermaid
graph TD

    %% --------------------------------------------------------
    %% 1. INITIATION & CLARIFICATION
    %% --------------------------------------------------------
    subgraph Initiation Phase
        A((User Input)) -->|Objective + Theme| B[ThreadController@store]
        B -->|Dispatches| C(ClarifyIntentJob)
        
        C -->|Agent: IntentClarifier<br>Phase: QUESTIONS| D{AgentNodeCompleted}
        D -->|Wait for UI| E((User Interview))
    end

    %% --------------------------------------------------------
    %% 2. REFINEMENT & PROTOCOL DRAFTING
    %% --------------------------------------------------------
    subgraph Refinement Phase
        E -->|Answers Submit| F[ThreadController@answers]
        F -->|Dispatches| G(RefineIntentJob)
        
        G -->|Agent: IntentClarifier<br>Phase: REFINE<br>Continues Conversation| H[Generates: Refined Brief]
        H -->|Dispatches| I(DraftProtocolParametersJob)
        
        I -->|Agent: ProtocolDrafter| J[Generates: Protocol Draft<br>Scope, Inclusion/Exclusion]
        J -->|Dispatches| K(LexicalScoutJob)
    end

    %% --------------------------------------------------------
    %% 3. LEXICAL SCOUTING (Multi-step Job)
    %% --------------------------------------------------------
    subgraph Discovery Phase
        K -->|Agent: PicoExtractor| L[Generates: PICO Framework]
        L -->|API Call| M((NexusApiClient))
        M -->|Fetches Papers| N[Agent: TaxonomySummarizer]
        N -->|Generates: Expanded Taxonomy| O[Dispatches GenerateQueriesJob]
    end

    %% --------------------------------------------------------
    %% 4. QUERY GENERATION & VALIDATION LOOP
    %% --------------------------------------------------------
    subgraph Execution & Validation Loop
        O --> P(GenerateQueriesJob)
        
        P -->|Agent: QueryGenerator<br>Uses: Taxonomy + Protocol + Critique| Q[Generates: Boolean Queries]
        Q -->|Dispatches| R(ValidateProtocolJob)
        
        R -->|Simulates Search vs Golden Seeds| S{Validation Passed?}
        
        %% The Loop
        S -->|No | T{Loop Count < 3?}
        T -->|Yes| U(DiagnosticCritiqueJob)
        U -->|Agent: DiagnosticCritiquor<br>Generates: Critique| P
        
        %% Failure Path
        T -->|No| V((Thread Failed))
        
        %% Success Path
        S -->|Yes| W(GenerateExportNodeJob)
    end

    %% --------------------------------------------------------
    %% 5. FINALIZATION
    %% --------------------------------------------------------
    subgraph Finalization
        W -->|Compiles Data| X[Generates: Export YAML]
        X -->|Status: Completed| Y((Workflow Finished))
    end

    %% Styling
    classDef job fill:#1e3a8a,stroke:#4f46e5,stroke-width:2px,color:#fff;
    classDef agent fill:#047857,stroke:#10b981,stroke-width:2px,color:#fff;
    classDef state fill:#4b5563,stroke:#9ca3af,stroke-width:2px,color:#fff;
    
    class C,G,I,K,P,R,U,W job;
    class D,H,J,L,N,Q,X agent;
```

## State Data Evolution

As the thread moves through the pipeline, `state_data` progressively builds up the required context:

1. **`answers`** (Added by UI)
2. **`refined_brief`** (Added by `RefineIntentJob`)
    - `final_objective`
    - `domain_context`
    - `pico_elements`
    - `search_constraints`
3. **`protocol_draft`** (Added by `DraftProtocolParametersJob`)
    - `scope`
    - `inclusion`
    - `exclusion`
4. **`pico_framework`** (Added by `LexicalScoutJob` > `PicoExtractor`)
5. **`expanded_taxonomy`** (Added by `LexicalScoutJob` > `TaxonomySummarizer`)
6. **`query_themes`** & **`boolean_strings`** (Added by `GenerateQueriesJob`)
7. **`loop_count`** & **`validation_passed`** (Added by `ValidateProtocolJob`)
8. **`critique`** (Added by `DiagnosticCritiqueJob`, consumed by next Query iteration)

## Agent Interaction Auditing
Every agent action is permanently recorded in the Thread's `agent_interactions` JSON column with the following structure:
```json
{
  "agent_name": {
    "conversation_id": "uuid",
    "input": "...",
    "output": { ... },
    "updated_at": "timestamp"
  }
}
```
*Note: `IntentClarifier` is tracked twice under `intent_clarifier_questions` and `intent_clarifier_refine` using the same `conversation_id`.*