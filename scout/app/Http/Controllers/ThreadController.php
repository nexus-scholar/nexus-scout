<?php

namespace App\Http\Controllers;

use App\Jobs\ClarifyIntentJob;
use App\Jobs\DraftProtocolParametersJob;
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
            'threads' => auth()->user()->threads()->latest()->get(),
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
        ]);

        $thread = auth()->user()->threads()->create($validated);

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

        $thread->update([
            'answers' => $validated['answers'],
            'status' => 'running',
            'state_data' => ['loop_count' => 0], // Initialize state
        ]);

        dispatch(new DraftProtocolParametersJob($thread));

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
        $thread->update(['status' => 'executing']);

        dispatch(new \App\Jobs\RunSearchNodeJob($thread));

        return redirect()->route('projects.index')->with('success', 'System is running extraction, you will be emailed when your CSV is ready');
    }
}
