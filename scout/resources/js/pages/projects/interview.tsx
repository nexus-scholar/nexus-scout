import { Head, useForm, router } from '@inertiajs/react';
import { MessageSquare, HelpCircle, CheckCircle, ChevronDown, ChevronUp, Loader2 } from 'lucide-react';
import { useState, useEffect } from 'react';
import AppLayout from '@/layouts/app-layout';
import { index as projectsIndex, answers as projectsAnswers } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';

interface Question {
    id: string;
    text: string;
}

interface Thread {
    id: string;
    objective: string;
    questions: Question[];
    status: string;
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
        title: 'Interview',
        href: '#',
    },
];

export default function InterviewScreen({ thread }: Props) {
    const [isObjectiveExpanded, setIsObjectiveExpanded] = useState(false);

    // Poll for questions if status is not interviewing
    useEffect(() => {
        if (thread.status === 'clarification_pending') {
            const interval = setInterval(() => {
                router.reload({ only: ['thread'] });
            }, 2000);
            return () => clearInterval(interval);
        }
    }, [thread.status]);

    const initialAnswers = thread.questions?.reduce((acc, q) => {
        acc[q.id] = '';
        return acc;
    }, {} as Record<string, string>) || {};

    const { data, setData, post, processing } = useForm({
        answers: initialAnswers,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(projectsAnswers(thread.id).url);
    };

    const handleAnswerChange = (id: string, value: string) => {
        setData('answers', {
            ...data.answers,
            [id]: value,
        });
    };

    const allAnswered = thread.questions && thread.questions.length > 0 && 
                        Object.values(data.answers).length === thread.questions.length &&
                        Object.values(data.answers).every(answer => answer.trim() !== '');

    if (thread.status === 'clarification_pending' || !thread.questions) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Preparing Interview" />
                <div className="flex flex-1 flex-col items-center justify-center p-4 space-y-4">
                    <Loader2 className="h-12 w-12 text-primary animate-spin" />
                    <h2 className="text-xl font-semibold">Generating Interview Questions...</h2>
                    <p className="text-muted-foreground text-center max-w-sm">
                        The agent is analyzing your objective to formulate clarifying questions.
                    </p>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agent Interview" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col p-4 space-y-6">
                {/* Thread Header */}
                <Card className="bg-muted/50 border-none shadow-none">
                    <CardHeader className="py-3 cursor-pointer" onClick={() => setIsObjectiveExpanded(!isObjectiveExpanded)}>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <MessageSquare className="h-4 w-4 text-primary" />
                                <CardTitle className="text-sm font-medium">Research Objective</CardTitle>
                            </div>
                            {isObjectiveExpanded ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                        </div>
                    </CardHeader>
                    {isObjectiveExpanded && (
                        <CardContent className="pb-4">
                            <p className="text-sm text-muted-foreground">{thread.objective}</p>
                            <div className="mt-2 text-[10px] text-muted-foreground/50 font-mono">Thread ID: {thread.id}</div>
                        </CardContent>
                    )}
                </Card>

                <div className="flex flex-col space-y-8 py-4">
                    <div className="space-y-2">
                        <h2 className="text-2xl font-bold tracking-tight">Agent Clarifications</h2>
                        <p className="text-muted-foreground">The AI agent needs a few more details to refine your research protocol.</p>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-6">
                        {thread.questions?.map((question, index) => (
                            <Card key={question.id} className="relative overflow-hidden">
                                <div className="absolute left-0 top-0 h-full w-1 bg-primary" />
                                <CardHeader className="pb-2">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                            {index + 1}
                                        </div>
                                        <CardTitle className="text-base leading-tight">{question.text}</CardTitle>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <Textarea
                                        placeholder="Your answer here..."
                                        className="min-h-[100px] resize-none"
                                        value={data.answers[question.id] || ''}
                                        onChange={(e) => handleAnswerChange(question.id, e.target.value)}
                                        required
                                    />
                                </CardContent>
                            </Card>
                        ))}

                        <div className="flex items-center justify-end pt-4">
                            <Button type="submit" size="lg" disabled={!allAnswered || processing}>
                                {processing ? 'Submitting...' : (
                                    <>
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Submit Clarifications
                                    </>
                                )}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
