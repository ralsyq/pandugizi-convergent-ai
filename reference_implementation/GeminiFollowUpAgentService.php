<?php

namespace App\Services;

use App\Models\Keluhan;
use App\Models\MonitoringGizi;
use App\Models\Pasien;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiFollowUpAgentService extends BaseFollowUpAgentService
{
    private GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    public function isEnabled(): bool
    {
        return $this->gemini->isConfigured();
    }

    protected function callLlm(string $prompt, string $systemInstruction): ?string
    {
        $contents = [
            [
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ],
        ];

        $response = $this->gemini->generateContent($contents, [], $systemInstruction);

        if ($response === null) {
            return null;
        }

        return $this->gemini->extractText($response);
    }
}
