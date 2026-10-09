<?php
declare(strict_types=1);

/**
 * Which AI model does which job.
 *
 * Live conversations — the website chat and WhatsApp replies, where someone
 * is waiting and the quality of the answer is the sale — use the
 * exhibition's main model. Work nobody is waiting for — company research,
 * finding prospects, first messages, follow-ups and prospect emails — can use
 * a cheaper one, set per exhibition. Every draft is checked by the platform
 * afterwards whichever model wrote it.
 *
 * Left empty, background work uses the main model, as before. A background
 * choice that cannot be used (its key not saved) quietly falls back to the
 * main model rather than stopping the work.
 */
final class AiModels
{
    /** @return array{provider: string, model: string} */
    public static function live(array $agent): array
    {
        return [
            'provider' => (string)($agent['llm_provider'] ?? 'anthropic'),
            'model'    => (string)($agent['llm_model'] ?? ''),
        ];
    }

    /** @return array{provider: string, model: string} */
    public static function background(array $agent): array
    {
        $live     = self::live($agent);
        $provider = trim((string)($agent['llm_background_provider'] ?? ''));
        $model    = trim((string)($agent['llm_background_model'] ?? ''));

        if ($provider === '' || $provider === $live['provider']) {
            return ['provider' => $live['provider'], 'model' => $model !== '' ? $model : $live['model']];
        }
        if (!LlmCatalogue::has($provider) || !LlmCatalogue::isConfigured($provider)) {
            return $live;
        }
        return ['provider' => $provider, 'model' => $model !== '' ? $model : LlmCatalogue::defaultModel($provider)];
    }

    /** The provider object and the model name for background work. */
    public static function backgroundProvider(array $agent): array
    {
        $b = self::background($agent);
        return [LlmProviderFactory::make($b['provider']), $b['model']];
    }
}
