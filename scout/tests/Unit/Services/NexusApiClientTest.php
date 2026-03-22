<?php

use App\Services\NexusApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'services.nexus_api.url' => 'https://nexus.example.test',
        'services.nexus_api.token' => 'test-token',
    ]);
});

/**
 * This test validates request contract formation for the Nexus literature endpoint.
 */
test('nexus api client sends expected post contract for literature search', function () {
    Http::fake([
        'https://nexus.example.test/api/v1/scout/literature-search' => Http::response([
            'data' => [
                ['title' => 'Paper A', 'abstract' => 'A'],
            ],
        ], 200),
    ]);

    $pico = [
        'population' => 'Adults with chronic insomnia',
        'intervention' => 'CBT-I',
        'comparison' => 'Standard care',
        'outcome' => 'Sleep latency',
    ];

    $result = app(NexusApiClient::class)->searchLiterature($pico, ['limit' => 15]);

    expect($result)->toBeArray()->toHaveCount(1);
    expect($result[0]['title'] ?? null)->toBe('Paper A');

    Http::assertSent(function ($request) use ($pico): bool {
        return $request->url() === 'https://nexus.example.test/api/v1/scout/literature-search'
            && $request->method() === 'POST'
            && ($request->header('Authorization')[0] ?? null) === 'Bearer test-token'
            && $request['pico'] === $pico
            && $request['providers'] === ['openalex', 'semantic_scholar']
            && $request['limit'] === 15;
    });
});

/**
 * This test verifies successful responses return a normalized papers array.
 */
test('nexus api client returns papers collection on 200 success', function () {
    Http::fake([
        '*' => Http::response([
            'data' => [
                ['title' => 'Paper 1', 'abstract' => 'Abstract 1'],
                ['title' => 'Paper 2', 'abstract' => 'Abstract 2'],
            ],
        ], 200),
    ]);

    $result = app(NexusApiClient::class)->searchLiterature([
        'population' => 'Adults',
        'intervention' => 'CBT',
        'comparison' => 'Usual care',
        'outcome' => 'Symptom reduction',
    ]);

    expect($result)->toHaveCount(2);
    expect($result[1]['title'] ?? null)->toBe('Paper 2');
});

/**
 * This test verifies API error statuses are handled gracefully with logging.
 */
test('nexus api client returns empty array and logs on api failure statuses', function (int $statusCode) {
    Log::spy();

    Http::fake([
        '*' => Http::response(['message' => 'Failure'], $statusCode),
    ]);

    $result = app(NexusApiClient::class)->searchLiterature([
        'population' => 'Adults',
        'intervention' => 'Drug X',
        'comparison' => 'Placebo',
        'outcome' => 'Response rate',
    ]);

    expect($result)->toBe([]);

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($statusCode): bool {
        return $message === 'Nexus API literature search failed'
            && ($context['status'] ?? null) === $statusCode
            && array_key_exists('body', $context);
    });
})->with([500, 404]);

/**
 * This test verifies timeout/connection exceptions are caught and logged.
 */
test('nexus api client catches connection exceptions and returns empty array', function () {
    Log::spy();

    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $result = app(NexusApiClient::class)->searchLiterature([
        'population' => 'Adults',
        'intervention' => 'Drug Y',
        'comparison' => 'Placebo',
        'outcome' => 'Outcome Y',
    ]);

    expect($result)->toBe([]);

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'Nexus API literature search exception'
            && str_contains((string) ($context['message'] ?? ''), 'Connection timed out');
    });
});
