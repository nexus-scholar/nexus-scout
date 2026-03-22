# Phase 3: The Engine Room (`StreamingScreen.tsx` or `EngineRoom.tsx`)

## Overview
This is a highly interactive "Illusion of Labor" screen. The heavy lifting is happening asynchronously via Laravel Queues. The React frontend connects via WebSockets (Laravel Reverb) to receive real-time updates of the agent's progress, showing the user exactly what the AI is thinking, fetching, and synthesizing.

## UI/UX Blueprint
- **Layout**: Two-column "Dashboard" style.
- **Left Column**: Progress Stepper. Iterates through the predefined Agentic nodes:
  1. `draft_protocol`
  2. `lexical_scout` (Fetching synonyms from OpenAlex)
  3. `generate_queries` (Synthesizing Booleans)
  4. `validate_protocol` (Critique/Heal loop)
  - Uses `lucide-react` `Loader2` (spinning) for active steps, and `CheckCircle2` (green) for completed steps.
- **Right Column**: "Discoveries" Terminal or Panel.
  - Dynamically populates with data the AI discovers in real time (e.g., extracted taxonomy tags, synonyms, paper titles).

## State & Frontend Logic
- **React Management**: Establish a WebSocket connection via Laravel Echo on component mount.
- **Event Listeners**: Listen for events on `private-thread.{thread_id}` channel.
  - `AgentStepCompleted`: Update the stepper.
  - `TaxonomyDiscovered`: Render new synonym tags in the Right Column.
  - `AgentFailed`: Render error state and retry button.
  - `AgentFinished`: Transition to Phase 4 (`/validation/{thread_id}`).

## Laravel Backend Contract
- **Architecture**: Laravel Queues + Laravel Reverb (WebSockets) + Event Broadcasting.
- **PHP Event Example**:
  ```php
  class AgentStepCompleted implements ShouldBroadcast {
      public $threadId;
      public $node;
      public $payload; // e.g., ["taxonomy" => ["SSRI", "Fluoxetine", "Sertraline"]]

      public function broadcastOn() {
          return new PrivateChannel('thread.' . $this->threadId);
      }
  }
  ```
- **Backend Action**: The background Agent orchestrator (running in Python via `Process` or rewritten in PHP) should fire these events sequentially as it moves through the LangGraph cycle. Once the cycle passes validation, it fires `AgentFinished` with the final compiled `nexus.yml` data.