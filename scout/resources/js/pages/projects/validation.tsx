import { Head, useForm } from '@inertiajs/react';
import { FileCode, Settings2, Play, AlertCircle, CheckCircle2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { index as projectsIndex, execute as projectsExecute } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

interface Thread {
    id: string;
    objective: string;
    status: string;
    nexus_yaml?: string;
    state_data?: any;
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
        title: 'Validation',
        href: '#',
    },
];

export default function ValidationScreen({ thread }: Props) {
    const { post, processing } = useForm();

    const handleApprove = (e: React.FormEvent) => {
        e.preventDefault();
        post(projectsExecute(thread.id).url);
    };

    const yamlContent = thread.nexus_yaml || `
project:
  id: ${thread.id}
  objective: "${thread.objective}"
  status: "No YAML generated"
`.trim();

    const stateData = thread.state_data || {};
    const booleanStrings = stateData.boolean_strings || [];
    const protocolDraft = stateData.protocol_draft || {};
    const scope = protocolDraft.scope?.definition || 'Not defined';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Validation Gate" />
            <div className="flex flex-col h-full p-4 gap-6 max-w-5xl mx-auto w-full">
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 className="h-5 w-5" />
                        <span className="font-semibold">Protocol Successfully Generated</span>
                    </div>
                    <h1 className="text-3xl font-bold tracking-tight">Validation Gate & Handoff</h1>
                    <p className="text-muted-foreground">Review the agent-generated protocol and authorize the extraction pipeline.</p>
                </div>

                <Tabs defaultValue="structured" className="flex-1 flex flex-col gap-4">
                    <TabsList className="grid w-full grid-cols-2 max-w-md">
                        <TabsTrigger value="structured">
                            <Settings2 className="h-4 w-4 mr-2" />
                            Structured View
                        </TabsTrigger>
                        <TabsTrigger value="code">
                            <FileCode className="h-4 w-4 mr-2" />
                            Nexus YAML
                        </TabsTrigger>
                    </TabsList>
                    
                    <Card className="flex-1 overflow-hidden border-none shadow-none bg-muted/30">
                        <CardContent className="p-0 h-full">
                            <TabsContent value="structured" className="p-6 m-0 h-full overflow-auto">
                                <div className="grid gap-6">
                                    <div className="grid gap-2">
                                        <h3 className="font-semibold">Research Scope</h3>
                                        <div className="p-4 rounded-lg bg-background border text-sm">
                                            {scope}
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <h3 className="font-semibold">Generated Boolean Queries</h3>
                                        {booleanStrings.length > 0 ? (
                                            <div className="flex flex-col gap-3">
                                                {booleanStrings.map((query: string, idx: number) => (
                                                    <div key={idx} className="p-4 rounded-lg bg-background border font-mono text-xs leading-relaxed">
                                                        {query}
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="p-4 rounded-lg bg-background border text-sm text-muted-foreground">
                                                No queries generated.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </TabsContent>
                            <TabsContent value="code" className="p-0 m-0 h-full overflow-auto bg-slate-950">
                                <pre className="p-6 font-mono text-xs text-slate-300 leading-relaxed whitespace-pre-wrap">
                                    <code>{yamlContent}</code>
                                </pre>
                            </TabsContent>
                        </CardContent>
                    </Card>
                </Tabs>

                <div className="flex items-center justify-between p-4 rounded-xl border border-primary/20 bg-primary/5">
                    <div className="flex items-center gap-3">
                        <AlertCircle className="h-5 w-5 text-primary" />
                        <p className="text-sm text-muted-foreground max-w-md">
                            By clicking "Approve & Run", you authorize the system to trigger the high-volume data extraction pipeline.
                        </p>
                    </div>
                    <form onSubmit={handleApprove}>
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing ? 'Launching...' : (
                                <>
                                    <Play className="h-4 w-4 mr-2 fill-current" />
                                    Approve & Run Nexus
                                </>
                            )}
                        </Button>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
