<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NexusApiClient
{
    protected string $url;
    protected ?string $token;

    public function __construct()
    {
        $this->url = config('services.nexus_api.url');
        $this->token = config('services.nexus_api.token');
    }

    /**
     * Search for literature using the Python Nexus API.
     *
     * @param array{population: string, intervention: string, comparison: string, outcome: string} $pico
     * @param array $options
     * @return array
     */
    public function searchLiterature(array $pico, array $options = []): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(30)
                ->post("{$this->url}/api/v1/scout/literature-search", [
                    'pico' => $pico,
                    'providers' => ['openalex', 'semantic_scholar'],
                    'limit' => $options['limit'] ?? 10,
                ]);

            if ($response->failed()) {
                Log::error('Nexus API literature search failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            return $response->json('data') ?? [];
        } catch (\Exception $e) {
            Log::error('Nexus API literature search exception', [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
