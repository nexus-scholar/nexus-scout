import { Head, Link } from '@inertiajs/react';
import { Plus, Folder } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { index as projectsIndex, create as projectsCreate, interview as projectsInterview, engine as projectsEngine, validation as projectsValidation } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';

interface Thread {
    id: string;
    objective: string;
    theme_context: string;
    status: string;
    created_at: string;
}

interface Props {
    threads: Thread[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Projects',
        href: projectsIndex(),
    },
];

export default function ProjectsIndex({ threads }: Props) {
    const getThreadUrl = (thread: Thread) => {
        switch (thread.status) {
            case 'running':
            case 'executing':
                return projectsEngine(thread.id).url;
            case 'completed':
            case 'failed':
                return projectsValidation(thread.id).url;
            case 'clarification_pending':
            case 'interviewing':
            default:
                return projectsInterview(thread.id).url;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Projects" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Projects</h1>
                        <p className="text-muted-foreground">Manage your literature review agent projects.</p>
                    </div>
                    <Button asChild>
                        <Link href={projectsCreate()}>
                            <Plus className="mr-2 h-4 w-4" />
                            New Project
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {threads.length === 0 ? (
                        <div className="col-span-full flex flex-col items-center justify-center rounded-lg border border-dashed p-12 text-center">
                            <Folder className="mb-4 h-12 w-12 text-muted-foreground/50" />
                            <h3 className="text-lg font-medium">No projects yet</h3>
                            <p className="mb-4 text-sm text-muted-foreground">Get started by creating your first literature review project.</p>
                            <Button variant="outline" asChild>
                                <Link href={projectsCreate()}>Create Project</Link>
                            </Button>
                        </div>
                    ) : (
                        threads.map((thread) => (
                            <Link
                                key={thread.id}
                                href={getThreadUrl(thread)}
                                className="group flex flex-col justify-between rounded-lg border bg-card p-4 transition-all hover:border-primary/50 hover:shadow-md"
                            >
                                <div>
                                    <div className="flex items-center justify-between gap-2 mb-2">
                                        <span className="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary capitalize">
                                            {thread.status.replace('_', ' ')}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {new Date(thread.created_at).toLocaleDateString()}
                                        </span>
                                    </div>
                                    <h3 className="font-semibold leading-tight group-hover:text-primary transition-colors">
                                        {thread.objective.length > 60 ? thread.objective.substring(0, 60) + '...' : thread.objective}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {thread.theme_context || 'General Context'}
                                    </p>
                                </div>
                            </Link>
                        ))
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
