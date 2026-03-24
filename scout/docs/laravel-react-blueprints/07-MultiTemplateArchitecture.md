# Scaffold: Multi-Template Workflow Architecture

This blueprint defines the architectural refactoring required to move Scout from a flat, single-pipeline application to a robust "Project -> Many Threads" hierarchy, supporting multiple research methodologies via pre-configured workflow templates.

## 1. Core Concepts & Hierarchy

### The New Data Model
- **`Project`**: The overarching container. It represents the high-level research goal (e.g., "PhD Thesis on Sleep Disorders"). It holds the project name, description, and eventually a shared library of saved documents/citations.
- **`Thread` (Workflow Execution)**: A specific AI-driven pipeline run. A `Project` `hasMany` `Threads`. A Thread represents **one execution of a specific template** (e.g., an SLR attempt, a Rapid Review attempt).

### The Templates
Templates dictate *which* AI agents are dispatched, *how* strict their system prompts are, and *what* the final artifact looks like.

1.  **Systematic Literature Review (SLR)**
    *   **Pipeline:** Intent Clarifier -> Protocol Drafter (PICO) -> Lexical Scout -> Query Generator (Strict Boolean) -> Validation (Golden Seeds) -> Export.
    *   **Characteristics:** Exhaustive, highly reproducible, requires strict validation.
2.  **Scoping Review**
    *   **Pipeline:** Intent Clarifier -> Protocol Drafter (PCC: Population, Concept, Context) -> Lexical Scout -> Query Generator (Broad OR statements).
    *   **Characteristics:** Maps the literature, validation is optional/disabled.
3.  **Rapid / Simple Review**
    *   **Pipeline:** Intent Clarifier -> Simple Query Drafter -> Search -> Synthesis Agent.
    *   **Characteristics:** Skips heavy protocol drafting. Focuses on speed and retrieving the top N most relevant papers for immediate summarization.
4.  **Related Works Discovery**
    *   **Pipeline:** Concept Extractor -> Semantic/Vector Searcher -> Snowballer (Citation crawling).
    *   **Characteristics:** No boolean queries. Takes user's abstract/idea and finds semantically similar papers and their citation trees.

---

## 2. Implementation Blueprint

### Phase 1: Database & Model Restructuring
- [ ] **Migration: Update `projects` table**
  - Add `user_id` to link projects to the authenticated user.
  - Drop the `Thread` model's direct link to `user_id` (it will derive ownership through `Project`).
- [ ] **Migration: Update `threads` table**
  - Add `project_id` (foreign key to `projects`).
  - Add `template_type` (string/enum: `slr`, `scoping`, `rapid`, `related_works`).
- [ ] **Model Updates:**
  - Update `User` model to `hasMany(Project::class)`.
  - Update `Project` model to `hasMany(Thread::class)`.
  - Update `Thread` model to `belongsTo(Project::class)`.
  - Update factories and tests to reflect the new hierarchy.

### Phase 2: Workflow Strategy Pattern
To support multiple pipelines cleanly without massive `if/else` blocks in controllers, we will implement a Strategy/Factory pattern for workflow orchestration.

- [ ] **Create `WorkflowManager` (or `WorkflowFactory`)**
  - A service that takes a `Thread` and its `template_type`, and returns the correct initial Job.
- [ ] **Refactor Jobs for Modularity**
  - Currently, jobs tightly couple to the next job (e.g., `DraftProtocolParametersJob` directly dispatches `LexicalScoutJob`).
  - *Refactor:* Implement an `Orchestrator` pattern or an Event-driven approach where completing a node triggers the `WorkflowManager` to look up the next step based on the `template_type`.
- [ ] **Prompt Manager Template Injection**
  - Update `PromptManager` to accept the `template_type`.
  - Update existing prompts (like `ProtocolDrafter`) to inject SLR (PICO) vs Scoping (PCC) rules dynamically based on the template.

### Phase 3: Frontend Adaptation
- [ ] **Project Dashboard (`projects/index`)**
  - Redesign to show a list of Projects.
  - Clicking a Project goes to a `Project Detail` screen.
- [ ] **Project Detail Screen**
  - Shows the project metadata.
  - Lists all historical `Threads` (Runs) inside this project.
  - Contains a "Start New Workflow" button.
- [ ] **Workflow Creation Modal/Page**
  - UI to let the user select which Template they want to run (`SLR`, `Scoping`, etc.).
  - Takes the initial objective and passes it along with the `template_type` to start the specific thread.

### Phase 4: Developing the New Templates (Agents & Jobs)

To keep the codebase DRY and maintainable, we will consolidate similar agents by passing a `type` or `mode` into their constructors rather than creating entirely new classes for every template.

- [ ] **Implement Scoping Review Logic:**
  - Rename `PicoExtractor` to `FrameworkExtractor(type: 'pico' | 'pcc')`. Adjust its prompt and schema based on the type.
  - Adjust `ProtocolDrafter` to use PCC rules when `template_type` is scoping.
  - Pass `mode: 'broad'` to the existing `QueryGenerator` to prefer OR statements.
- [ ] **Implement Rapid Review Logic:**
  - Pass `mode: 'simple'` to the existing `QueryGenerator` to generate 2-3 broad search strings immediately after the Intent Clarifier, skipping the Lexical Scout phase.
  - Create a new `LiteratureSynthesizer` Agent and corresponding `SynthesizeLiteratureJob` to read the top 50 abstracts and write the final "State of the Art" report.
- [ ] **Implement Related Works Logic (Optional/Future):**
  - Create a new `ConceptExtractor` Agent to analyze user drafts and pull out semantic claims.
  - Create a new `SemanticSearchJob` that calls the Nexus API for vector searches instead of boolean.
  - Create a new `SnowballAnalyzer` Agent to evaluate citation trees (forward/backward snowballing) for relevance.

---

## 3. Execution Checklist

- [x] 1. Migrate `user_id` from `threads` to `projects`.
- [x] 2. Add `project_id` and `template_type` to `threads`.
- [x] 3. Update Eloquent relationships (User -> Project -> Thread).
- [x] 4. Fix existing feature/unit tests broken by hierarchy change.
- [x] 5. Implement `App\Enums\TemplateType`.
- [x] 6. Create `WorkflowOrchestrator` to decouple hardcoded job chaining.
- [x] 7. Update `ThreadController@store` to accept `project_id` and `template_type`.
- [x] 8. Update Vue/Inertia frontend for Project container views.
- [x] 9. Update `PromptManager` to accept template types.
- [ ] 10. (Future) Build out `Rapid` and `Related Works` agent pipelines.
