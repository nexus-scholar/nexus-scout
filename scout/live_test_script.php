<?php

use App\Models\User;
use App\Models\Thread;
use App\Jobs\DraftProtocolParametersJob;
use App\Jobs\DiagnosticCritiqueJob;
use App\Jobs\LexicalScoutJob;
use App\Jobs\GenerateQueriesJob;
use App\Jobs\ValidateProtocolJob;
use App\Jobs\GenerateExportNodeJob;
use App\Services\NexusApiClient;

error_reporting(E_ALL);
ini_set('display_errors', '1');

function hr(string $title): void
{
    echo "\n".str_repeat('=', 90)."\n";
    echo $title."\n";
    echo str_repeat('=', 90)."\n";
}

function dumpJson(string $label, mixed $value, int $maxChars = 3000): void
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        echo "{$label}: [unable to encode]\n";

        return;
    }

    $trimmed = strlen($encoded) > $maxChars
        ? substr($encoded, 0, $maxChars)."\n... [truncated]"
        : $encoded;

    echo "{$label}:\n{$trimmed}\n";
}

function summarizeThread(Thread $thread): void
{
    echo "Thread Status: {$thread->status}\n";
    echo 'Loop Count: '.($thread->state_data['loop_count'] ?? 0)."\n";

    dumpJson('state_data keys', array_keys($thread->state_data ?? []));
    dumpJson('pico_framework', $thread->state_data['pico_framework'] ?? []);
    dumpJson('expanded_taxonomy', $thread->state_data['expanded_taxonomy'] ?? []);
    dumpJson('query_themes', $thread->state_data['query_themes'] ?? []);
    dumpJson('boolean_strings', $thread->state_data['boolean_strings'] ?? []);
}

function printStateDiff(array $before, array $after): void
{
    $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
    $changed = [];

    foreach ($keys as $key) {
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;

        if ($old !== $new) {
            $changed[] = $key;
        }
    }

    if ($changed === []) {
        echo "State diff: no changes\n";

        return;
    }

    echo 'State diff changed keys: '.implode(', ', $changed)."\n";

    foreach ($changed as $key) {
        dumpJson("before.{$key}", $before[$key] ?? null, 1200);
        dumpJson("after.{$key}", $after[$key] ?? null, 1200);
    }
}

function runStep(string $stepName, callable $runner, Thread $thread): bool
{
    hr("STEP: {$stepName}");

    $beforeState = $thread->state_data ?? [];
    $beforeStatus = $thread->status;

    try {
        $start = microtime(true);
        $runner();
        $duration = round((microtime(true) - $start) * 1000, 2);

        $thread->refresh();

        echo "Result: SUCCESS ({$duration} ms)\n";
        echo "Status: {$beforeStatus} -> {$thread->status}\n";
        printStateDiff($beforeState, $thread->state_data ?? []);
        summarizeThread($thread);

        return true;
    } catch (Throwable $e) {
        $thread->refresh();

        echo "Result: FAILED\n";
        echo 'Exception: '.$e::class."\n";
        echo 'Message: '.$e->getMessage()."\n";
        echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
        echo "Trace:\n".$e->getTraceAsString()."\n";
        summarizeThread($thread);

        return false;
    }
}

hr('LIVE TEST SCRIPT START');

echo 'Nexus URL config: '.(config('services.nexus_api.url') ?? 'null')."\n";
echo 'Nexus token configured: '.(config('services.nexus_api.token') ? 'yes' : 'no')."\n";

// Bind a debug wrapper so LexicalScout step prints Nexus request/response details.
$debugNexusClient = new class extends NexusApiClient
{
    public array $lastPico = [];
    public array $lastOptions = [];
    public array $lastPapers = [];

    public function searchLiterature(array $pico, array $options = []): array
    {
        $this->lastPico = $pico;
        $this->lastOptions = $options;

        hr('NEXUS API CALL (from LexicalScoutJob)');
        dumpJson('Nexus request PICO', $pico, 2000);
        dumpJson('Nexus request options', $options, 1000);

        $papers = parent::searchLiterature($pico, $options);
        $this->lastPapers = $papers;

        echo 'Nexus returned papers count: '.count($papers)."\n";

        if (count($papers) > 0) {
            dumpJson('Nexus first paper', $papers[0], 2000);
            dumpJson('Nexus papers sample (up to 3)', array_slice($papers, 0, 3), 3000);
        } else {
            echo "Nexus papers sample: []\n";
        }

        return $papers;
    }
};

app()->instance(NexusApiClient::class, $debugNexusClient);

// 1. Create/Get User
hr('SETUP: USER');
$user = User::first() ?? User::factory()->create([
    'email' => 'test@example.com',
    'password' => bcrypt('password'),
]);

echo "User ID: " . $user->id . "\n";

// 2. Create Thread
hr('SETUP: THREAD');
$thread = $user->threads()->create([
    'objective' => 'Research the effectiveness of Melatonin for sleep disorders in night-shift workers',
    'theme_context' => 'Circadian Rhythm Disorders',
    'status' => 'running',
    'answers' => [
        ['question_id' => 'q1', 'answer' => 'Adult night-shift workers'],
        ['question_id' => 'q2', 'answer' => 'Exogenous melatonin'],
        ['question_id' => 'q3', 'answer' => 'Sleep quality and daytime sleepiness'],
    ],
    'state_data' => [
        'loop_count' => 0,
    ]
]);

echo "Thread ID: " . $thread->id . "\n";
summarizeThread($thread);

// 3. Chain the jobs synchronously for the live test
runStep('DraftProtocolParametersJob', function () use ($thread): void {
    (new DraftProtocolParametersJob($thread))->handle();
}, $thread);

runStep('LexicalScoutJob', function () use ($thread): void {
    (new LexicalScoutJob($thread))->handle();
}, $thread);

runStep('GenerateQueriesJob', function () use ($thread): void {
    (new GenerateQueriesJob($thread))->handle();
}, $thread);

runStep('ValidateProtocolJob (first pass)', function () use ($thread): void {
    (new ValidateProtocolJob($thread))->handle();
}, $thread);

if (($thread->state_data['loop_count'] ?? 0) > 0) {
    runStep('DiagnosticCritiqueJob (loop recovery)', function () use ($thread): void {
        (new DiagnosticCritiqueJob($thread))->handle();
    }, $thread);

    runStep('ValidateProtocolJob (second pass)', function () use ($thread): void {
        (new ValidateProtocolJob($thread))->handle();
    }, $thread);
}

if ($thread->status === 'completed' || isset($thread->state_data['validation_passed'])) {
    runStep('GenerateExportNodeJob', function () use ($thread): void {
        (new GenerateExportNodeJob($thread))->handle();
    }, $thread);
}

hr('LIVE TEST SCRIPT COMPLETED');
echo "Final Status: {$thread->status}\n";
echo "Thread ID: {$thread->id}\n";
echo 'Validation Passed: '.((isset($thread->state_data['validation_passed']) && $thread->state_data['validation_passed']) ? 'yes' : 'no')."\n";
echo 'Nexus papers returned (last call): '.count($debugNexusClient->lastPapers)."\n";

echo "\nExport YAML Preview:\n";
echo substr((string) $thread->export_yaml, 0, 1200)."\n";

dumpJson('Protocol JSON keys', array_keys($thread->protocol ?? []), 500);
dumpJson('Final state_data', $thread->state_data ?? [], 5000);
