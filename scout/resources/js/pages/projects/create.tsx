import { Head, useForm } from '@inertiajs/react';
import { BookOpen, Sparkles, Loader2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { index as projectsIndex, store as projectsStore } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Projects',
        href: projectsIndex(),
    },
    {
        title: 'New Project',
        href: '#',
    },
];

export default function CreateProject() {
    const { data, setData, post, processing, errors } = useForm({
        objective: '',
        theme_context: '',
        template_type: 'slr',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(projectsStore().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Initialize Agent" />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col justify-center px-4 py-12 sm:py-24">
                <div className="flex flex-col items-center text-center space-y-4 mb-12">
                    <div className="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <BookOpen className="h-6 w-6" />
                    </div>
                    <h1 className="text-4xl font-bold tracking-tight sm:text-5xl">Literature Agent Handoff</h1>
                    <p className="max-w-[42rem] leading-normal text-muted-foreground sm:text-xl sm:leading-8">
                        Describe your research objective and let the agent orchestrate your literature review.
                    </p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-8">
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="objective" className="text-base">Research Objective</Label>
                            <Textarea
                                id="objective"
                                placeholder="e.g., I want to research the effects of SSRIs on chronic fatigue syndrome in adolescents..."
                                className="min-h-[150px] text-lg resize-none"
                                value={data.objective}
                                onChange={(e) => setData('objective', e.target.value)}
                                required
                            />
                            {errors.objective && <p className="text-sm font-medium text-destructive">{errors.objective}</p>}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="theme_context" className="text-base">Theme Context (Optional)</Label>
                                <Input
                                    id="theme_context"
                                    placeholder="e.g., Clinical Psychiatry..."
                                    className="text-lg"
                                    value={data.theme_context}
                                    onChange={(e) => setData('theme_context', e.target.value)}
                                />
                                {errors.theme_context && <p className="text-sm font-medium text-destructive">{errors.theme_context}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="template_type" className="text-base">Review Methodology</Label>
                                <select
                                    id="template_type"
                                    className="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 text-lg"
                                    value={data.template_type}
                                    onChange={(e) => setData('template_type', e.target.value)}
                                >
                                    <option value="slr">Systematic Literature Review (SLR)</option>
                                    <option value="scoping">Scoping Review</option>
                                    <option value="rapid">Rapid Review</option>
                                    <option value="related_works">Related Works Discovery</option>
                                </select>
                                {errors.template_type && <p className="text-sm font-medium text-destructive">{errors.template_type}</p>}
                            </div>
                        </div>
                    </div>

                    <Button type="submit" size="lg" className="w-full text-lg h-12" disabled={processing}>
                        {processing ? (
                            <>
                                <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                                Initializing Agent...
                            </>
                        ) : (
                            <>
                                <Sparkles className="mr-2 h-5 w-5" />
                                Initialize Agent
                            </>
                        )}
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
