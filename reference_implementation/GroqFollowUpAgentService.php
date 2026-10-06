<?php

namespace App\Services;

class GroqFollowUpAgentService extends BaseFollowUpAgentService
{
    private GroqService $groq;

    public function __construct(GroqService $groq)
    {
        $this->groq = $groq;
    }

    public function isEnabled(): bool
    {
        return $this->groq->isConfigured();
    }

    protected function callLlm(string $prompt, string $systemInstruction): ?string
    {
        $messages = [
            [
                'role' => 'system',
                'content' => $systemInstruction,
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $response = $this->groq->generateContent($messages);

        if ($response === null) {
            return null;
        }

        return $this->groq->extractText($response);
    }
}
