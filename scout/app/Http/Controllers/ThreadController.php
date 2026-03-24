<?php

namespace App\Http\Controllers;

use App\Enums\ThreadStatus;
use App\Jobs\ClarifyIntentJob;
use App\Jobs\RefineIntentJob;
use App\Jobs\RunSearchNodeJob;
use App\Models\Thread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ThreadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        return Inertia::render('projects/index', [
            'projects' => auth()->user()->projects()->with('threads')->latest()->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('projects/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'objective' => 'required|string',
            'theme_context' => 'nullable|string',
            'template_type' => ['required', 'string', \Illuminate\Validation\Rule::enum(\App\Enums\TemplateType::class)],
        ]);

        $project = auth()->user()->projects()->create([
            'name' => 'Project: ' . str($validated['objective'])->limit(30),
            'description' => $validated['objective'],
        ]);

        $thread = $project->threads()->create([
            'objective' => $validated['objective'],
            'theme_context' => $validated['theme_context'],
            'template_type' => \App\Enums\TemplateType::from($validated['template_type']),
        ]);

        dispatch(new ClarifyIntentJob($thread));

        return redirect()->route('projects.interview', $thread);
    }

    /**
     * Show the interview screen.
     */
    public function interview(Thread $thread): Response
    {
        return Inertia::render('projects/interview', [
            'thread' => $thread,
        ]);
    }

    /**
     * Submit answers to the interview questions.
     */
    public function answers(Request $request, Thread $thread): RedirectResponse
    {
        $validated = $request->validate([
            'answers' => 'required|array',
        ]);

        // Basic sanity check: answers should match question IDs
        $questionIds = collect($thread->questions)->pluck('id')->toArray();
        foreach (array_keys($validated['answers']) as $answerId) {
            if (! in_array($answerId, $questionIds)) {
                return back()->withErrors(['answers' => "Invalid question ID: {$answerId}"]);
            }
        }

        $thread->update([
            'status' => ThreadStatus::Running,
            'state_data' => array_merge($thread->state_data ?? [], [
                'loop_count' => 0,
                'answers' => $validated['answers'],
            ]),
        ]);

        dispatch(new RefineIntentJob($thread));

        return redirect()->route('projects.engine', $thread);
    }

    /**
     * Show the engine room (streaming) screen.
     */
    public function engine(Thread $thread): Response
    {
        return Inertia::render('projects/engine', [
            'thread' => $thread,
        ]);
    }

    /**
     * Show the validation gate screen.
     */
    public function validation(Thread $thread): Response
    {
        return Inertia::render('projects/validation', [
            'thread' => $thread,
        ]);
    }

    /**
     * Execute the pipeline.
     */
    public function execute(Thread $thread): RedirectResponse
    {
        $thread->update(['status' => ThreadStatus::Executing]);

        dispatch(new RunSearchNodeJob($thread));

        return redirect()->route('projects.index')->with('success', 'System is running extraction, you will be emailed when your CSV is ready');
    }
}
