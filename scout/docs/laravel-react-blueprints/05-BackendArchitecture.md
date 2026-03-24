# Phase 5: Backend Architecture (Jobs, Events & Queues)

## Overview
Moving from LangGraph (Python) to Laravel means we no longer have an off-the-shelf "Cyclic Graph" to run our self-healing loops. Instead, we map the agentic workflow to Laravel's core strengths: **Eloquent Models (State)**, **Queued Jobs (Nodes)**, and **WebSockets/Reverb (Events)**.

## 1. The State Machine (Eloquent Model)
In LangGraph, state is passed from node to node in memory. In Laravel, the state lives in the database.

**Model:** `AgentThread`
- `id` (UUID)
- `objective` (Text)
- `status` (Enum: pending, interviewing, running, completed, failed)
- `state_data` (JSON Column) -> This holds the equivalent of Pydantic `AgentState` (e.g., `pico_framework`, `expanded_taxonomy`, `golden_seeds`, `loop_count`, `validation_passed`).

*Every Job receives the `AgentThread`, modifies the `state_data`, and saves it back to the DB before dispatching the next Job.*

## 2. The Nodes (Laravel Queued Jobs)
Each LangGraph "Node" becomes a dedicated background Job in Laravel. They should all implement `ShouldQueue`.

1. **`ClarifyIntentJob`** -> Takes the user's raw objective, expands it into a general theme, and generates 2-3 dynamic questions (the Interview Phase) to resolve ambiguities.
2. **`DraftProtocolParametersJob`** -> Parses the user's objective (and interview answers) and extracts the PICO framework.
3. **`LexicalScoutJob`** -> Reaches out to OpenAlex API, extracts synonyms, and updates the `expanded_taxonomy` in `state_data`.
4. **`GenerateQueriesJob`** -> Passes the context & taxonomy to the LLM to create Boolean strings.
5. **`ValidateProtocolJob`** -> Tests the generated Boolean strings against the `golden_seeds` constraints. Updates `validation_passed` boolean.
6. **`DiagnosticCritiqueJob`** -> If validation fails, this job asks the LLM to figure out *why* and prepare a fix.
7. **`GenerateExportNodeJob`** -> Once validation passes, this job formats all the agent's work into the structured `nexus.yml`, `queries.yml`, and `protocol.json` payloads.
8. **`RunSearchNodeJob`** -> (Optional/Deferred) This triggers the actual `nexus` Python CLI data scraping process *after* human validation.

## 3. The Routing & The Self-Healing Loop (Dynamic Dispatching)
Standard Laravel `Bus::chain()` is linear. Because our agent needs to **loop** (Critique -> Synthesize -> Validate), we must use **Dynamic Dispatching** from within the jobs.

**How the routing works inside `ValidateProtocolJob`:**
```php
public function handle()
{
    // ... run validation logic ...
    
    if ($validationPassed) {
        // Dispatch the final compilation step
        dispatch(new GenerateExportNodeJob($this->thread));
    } else {
        if ($this->thread->state_data['loop_count'] >= 3) {
            $this->thread->update(['status' => 'failed']);
            broadcast(new AgentFailed($this->thread, "Max loops reached"));
            return;
        }
        
        // Loop back to Critique!
        $this->thread->incrementLoopCount();
        dispatch(new DiagnosticCritiqueJob($this->thread));
    }
}
```

## 4. Real-Time UI Updates (Laravel Events)
LangGraph's `.stream()` yielded chunked JSON. In Laravel, we use **Laravel Reverb** (WebSockets) to push updates to the React `StreamingScreen.tsx`.

You will define standard Laravel Events that implement `ShouldBroadcastNow`:

- **Event:** `AgentNodeStarted`
  - **Payload:** `["node" => "lexical_scout"]`
  - **UI Reaction:** React sets step to "Scanning Academic Databases" and spins the loader.
  
- **Event:** `AgentNodeCompleted`
  - **Payload:** `["node" => "lexical_scout", "discoveries" => ["taxonomy" => [...] ] ]`
  - **UI Reaction:** React checks off the step and populates the "Lexical Discoveries" UI panel.

- **Event:** `AgentWorkflowFinished`
  - **Payload:** Full finalized generated YAML.
  - **UI Reaction:** Router automatically pushes the user to the Validation Gate (`/validation`).

## 5. The Queue Configuration
- **Queue Worker:** Because LLM calls and API fetches (OpenAlex) take time, these jobs must be run with high timeout limits.
- Command: `php artisan queue:work --timeout=300 --tries=1`
- *Note:* We set `--tries=1` for Laravel's retry config because the Agent handles its own retry logic via the `DiagnosticCritiqueJob`, not through raw job failures.