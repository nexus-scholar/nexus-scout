# Phase 1: Welcome & Initialization Screen (`WelcomeScreen.tsx`)

## Overview
This is the entry point of the application. Its primary goal is to capture the user's initial "seed" idea for their literature review and kick off the backend agent orchestration in Laravel.

## UI/UX Blueprint
- **Layout**: Centered, clean, minimalist focus (like Google/Perplexity landing page). 
- **Typography & Icons**: Use `lucide-react` for iconography (e.g., `BookOpen`, `Sparkles`). Tailwind CSS for styling.
- **Main Components**:
  - **Hero Title**: "Literature Agent Handoff"
  - **Large Textarea**: For the `objective` (What is the research about?)
  - **Secondary Input**: `themeContext` (e.g., Clinical, Engineering, General)
  - **Call to Action (CTA)**: "Initialize Agent" button with a spinning loader on click.

## State & Frontend Logic
- **React Management**: Managed via a global store (e.g., Zustand) or local component state until the form is submitted.
- **On Submit**: 
  - Send a POST request to the Laravel API.
  - On success, store the returned `thread_id` in the global state.
  - Route the user to Phase 2 (`/interview/{thread_id}`).

## Laravel Backend Contract
- **Endpoint**: `POST /api/v1/threads`
- **Payload**:
  ```json
  {
    "objective": "I want to research the effects of SSRIs on chronic fatigue...",
    "theme_context": "Clinical Psychiatry"
  }
  ```
- **Response**:
  ```json
  {
    "thread_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
    "status": "clarification_pending"
  }
  ```
- **Backend Action**: Laravel should create a new `Thread` model in the database, save the initial state, and queue a Job to run the first prompt (the Semantic Architect / Intent Clarifier).