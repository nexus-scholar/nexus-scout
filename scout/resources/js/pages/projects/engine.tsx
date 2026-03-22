import { Head, Link, router } from '@inertiajs/react';
import { Loader2, Terminal, CheckCircle2, AlertCircle } from 'lucide-react';
import { useEffect, useRef } from 'react';
import AppLayout from '@/layouts/app-layout';
import { index as projectsIndex, validation as projectsValidation } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface Thread {
    id: string;
    objective: string;
    status: string;
    state_data: any;
}

interface Props {
    thread: Thread;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Projects',
        href: projectsIndex(),
    },
    {
        title: 'Engine Room',
        href: '#',
    },
];

export default function EngineRoom({ thread }: Props) {
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        // Poll for updates while the thread is running
        if (thread.status === 'running') {
            const interval = setInterval(() => {
                router.reload({ only: ['thread'], preserveScroll: true });
            }, 3000);
            return () => clearInterval(interval);
        }
    }, [thread.status]);

    useEffect(() => {
        if (bottomRef.current) {
            bottomRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    }, [thread.state_data]);

    // Determine the status of each step based on state_data
    const stateData = thread.state_data || {};
    
    const draftStatus = stateData.protocol_draft ? 'completed' : 'running';
    const lexicalStatus = !stateData.protocol_draft ? 'pending' : (stateData.expanded_taxonomy ? 'completed' : 'running');
    const queryStatus = !stateData.expanded_taxonomy ? 'pending' : (stateData.query_themes ? 'completed' : 'running');
    const validationStatus = !stateData.query_themes ? 'pending' : (stateData.validation_passed ? 'completed' : 'running');

    const isCompleted = thread.status === 'completed';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Engine Room" />
            <div className="flex flex-col h-full p-4 gap-6">
                <div className="flex flex-col gap-2">
                    <h1 className="text-3xl font-bold tracking-tight">The Engine Room</h1>
                    <p className="text-muted-foreground">The agent is currently orchestrating your literature review protocol.</p>
                </div>

                <div className="grid flex-1 gap-6 md:grid-cols-2">
                    {/* Left Column: Progress Stepper */}
                    <Card className="flex flex-col h-full border-none shadow-none bg-muted/30">
                        <CardHeader>
                            <CardTitle className="text-lg">Agentic Progress</CardTitle>
                        </CardHeader>
                        <CardContent className="flex-1">
                            <div className="space-y-8 relative before:absolute before:left-[11px] before:top-2 before:h-[calc(100%-16px)] before:w-[2px] before:bg-muted">
                                {[
                                    { id: 'draft_protocol', label: 'Drafting Protocol', status: draftStatus },
                                    { id: 'lexical_scout', label: 'Lexical Scouting', status: lexicalStatus },
                                    { id: 'generate_queries', label: 'Generating Boolean Strings', status: queryStatus },
                                    { id: 'validate_protocol', label: 'Protocol Validation', status: isCompleted ? 'completed' : validationStatus },
                                ].map((step) => (
                                    <div key={step.id} className="relative flex items-center gap-4 pl-8 group">
                                        <div className={`absolute left-0 z-10 flex h-6 w-6 items-center justify-center rounded-full border-2 bg-background ${
                                            step.status === 'completed' ? 'border-primary text-primary' :
                                            step.status === 'running' ? 'border-primary animate-pulse' : 'border-muted'
                                        }`}>
                                            {step.status === 'completed' ? <CheckCircle2 className="h-4 w-4" /> :
                                             step.status === 'running' ? <Loader2 className="h-4 w-4 animate-spin" /> :
                                             <div className="h-2 w-2 rounded-full bg-muted" />}
                                        </div>
                                        <div className="flex flex-col">
                                            <span className={`text-sm font-medium ${step.status === 'pending' ? 'text-muted-foreground' : 'text-foreground'}`}>
                                                {step.label}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            
                            {thread.status === 'failed' && (
                                <div className="mt-8 flex items-center gap-3 p-4 rounded-lg bg-destructive/10 text-destructive">
                                    <AlertCircle className="h-5 w-5" />
                                    <div>
                                        <p className="font-semibold">Agent Workflow Failed</p>
                                        <p className="text-sm">Maximum loops reached during validation.</p>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Right Column: Discoveries Terminal */}
                    <Card className="flex flex-col h-full border-none shadow-none bg-slate-950 text-slate-50 font-mono text-sm overflow-hidden">
                        <CardHeader className="border-b border-slate-800 bg-slate-900/50 py-2 px-4">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Terminal className="h-4 w-4 text-emerald-400" />
                                    <span className="text-xs font-semibold text-slate-400">Discoveries Terminal</span>
                                </div>
                                <div className="flex gap-1.5">
                                    <div className="h-2 w-2 rounded-full bg-slate-800" />
                                    <div className="h-2 w-2 rounded-full bg-slate-800" />
                                    <div className="h-2 w-2 rounded-full bg-slate-800" />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="flex-1 p-4 overflow-auto scrollbar-hide">
                            <div className="space-y-2 opacity-80 pb-8">
                                <p className="text-emerald-400">Initializing orchestrator...</p>
                                
                                {stateData.protocol_draft && (
                                    <>
                                        <p className="text-slate-400">[info] Extracted PICO Framework</p>
                                        <p className="text-emerald-400 pl-4">- Population: {stateData.pico_framework?.population || 'Identified'}</p>
                                        <p className="text-emerald-400 pl-4">- Intervention: {stateData.pico_framework?.intervention || 'Identified'}</p>
                                    </>
                                )}

                                {stateData.expanded_taxonomy && (
                                    <>
                                        <p className="text-slate-400 mt-4">[info] Summarized Lexical Taxonomy</p>
                                        {Object.entries(stateData.expanded_taxonomy).slice(0, 3).map(([concept, terms]: [string, any]) => (
                                            <p key={concept} className="text-emerald-400 pl-4">
                                                - {concept}: {Array.isArray(terms) ? terms.join(', ') : '...'}
                                            </p>
                                        ))}
                                    </>
                                )}
                                
                                {stateData.query_themes && (
                                    <>
                                        <p className="text-slate-400 mt-4">[info] Generated Boolean Queries</p>
                                        <p className="text-emerald-400 pl-4">Created {stateData.boolean_strings?.length || 0} query variants.</p>
                                    </>
                                )}
                                
                                {stateData.critique && (
                                    <>
                                        <p className="text-yellow-400 mt-4">[warn] Validation Failed - Healing Loop Initiated</p>
                                        <p className="text-slate-400 pl-4">Reason: {stateData.critique}</p>
                                    </>
                                )}
                                
                                {isCompleted && (
                                    <p className="text-emerald-400 font-bold mt-4">Agent workflow completed successfully!</p>
                                )}

                                {!isCompleted && thread.status !== 'failed' && (
                                    <div className="animate-pulse flex gap-1 mt-4">
                                        <span className="text-slate-400">_</span>
                                    </div>
                                )}
                                <div ref={bottomRef} />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex justify-end">
                    <Button asChild disabled={!isCompleted}>
                        <Link href={isCompleted ? projectsValidation(thread.id).url : '#'}>
                            Next Phase: Validation
                        </Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
