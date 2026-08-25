<?php

namespace Modules\AI\AIProviders;

use Modules\AI\app\Contracts\AIProviderInterface;
use Modules\AI\app\Models\AISetting;
use OpenAI;

class OpenAIProvider implements AIProviderInterface
{
    /**
     * What this provider runs on when nothing has been chosen.
     *
     * Both were hardcoded here, which meant an operator could switch AI vendors from the admin
     * screen and could not change the model or what a call costs — the one dial that decides the
     * bill was the one they could not reach.
     */
    public const DEFAULT_MODEL = 'gpt-4o';
    public const DEFAULT_TEMPERATURE = 0.3;

    protected string $apiKey;
    protected ?string $organization;

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function setApiKey($apikey): void
    {
        $this->apiKey = $apikey;
    }

    public function setOrganization($organization): void
    {
        $this->organization = $organization;
    }

    public function generate(string $prompt, ?string $imageUrl = null, array $options = []): string
    {
        $client = OpenAI::client($this->apiKey, $this->organization);
        $content = [['type' => 'text', 'text' => $prompt]];
        if (!empty($imageUrl)) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $imageUrl],
            ];
        }
        $settings = AISetting::first();

        $response = $client->chat()->create([
            'model' => $settings?->model ?: self::DEFAULT_MODEL,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'temperature' => $settings?->temperature !== null ? (float) $settings->temperature : self::DEFAULT_TEMPERATURE,
        ]);
        return $response->choices[0]->message->content;
    }
}
