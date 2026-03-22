# Phase 2: Agent Interview Screen (`InterviewScreen.tsx`)

## Overview
This screen represents a dynamic Q&A exchange between the human user and the AI. The backend agent has reviewed the initial objective and identified missing parameters (Date constraints, target journals, specific populations). The user must answer these questions to proceed.

## UI/UX Blueprint
- **Layout**: Chat-style interface or a dynamic stacked form mapping over identical "Question Cards".
- **Typography & Icons**: `lucide-react` icons (e.g., `MessageSquare`, `HelpCircle`, `CheckCircle`).
- **Main Components**:
  - **Thread Header**: Shows current `thread_id` and the original `objective` as a minimized collapsible banner.
  - **The Questions Map**: Renders an array of questions dispatched by the Laravel backend. Each component should have a Textarea for the user to reply.
  - **Submit Button**: "Submit Clarifications". Becomes active when all questions have responses.

## State & Frontend Logic
- **React Management**: Fetch the required questions from Laravel using `useEffect` or `react-query`.
- **Form State**: A dictionary/object mapping `question_id` to the user's `answer_string`.
- **Navigation**: Upon successful submission, automatically transition the router to Phase 3 (`/engine/{thread_id}`).

## Laravel Backend Contract
- **Endpoint 1 (Fetch)**: `GET /api/v1/threads/{thread_id}`
  - **Response**:
    ```json
    {
      "status": "clarification_pending",
      "questions": [
        { "id": "q1", "text": "What date range are you targeting for this literature review?" },
        { "id": "q2", "text": "Are there specific geographical regions this applies to?" }
      ]
    }
    ```
- **Endpoint 2 (Submit)**: `POST /api/v1/threads/{thread_id}/answers`
  - **Payload**:
    ```json
    {
      "answers": {
        "q1": "From 2015 to 2024",
        "q2": "Global, but english only"
      }
    }
    ```
  - **Backend Action**: Laravel saves the answers to the database, updates thread status to `running`, and dispatches the main Agentic Loop Job (Scout -> Synthesizer -> Validation).