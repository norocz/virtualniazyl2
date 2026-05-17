<?php
declare(strict_types=1);

namespace App\Services;

use Nette\Caching\Cache;
use Nette\Caching\Storages\FileStorage;
use Tracy\Debugger;
use App\Config\OpenAiConfig;
use App\Config\I18nConfig;

/**
 * Služba pro překlady textů přes OpenAI ChatGPT.
 *
 * - Konfigurace přes parameters.openai v secrets.neon
 * - Cachuje překlady (každý text se překládá jen jednou)
 * - Respektuje nastavení i18n.available pro podporované jazyky
 * - Umí překládat jak krátké zprávy, tak dlouhé texty (popisy zvířátek apod.)
 */
class TranslationService
{
    private Cache $cache;
    private string $apiKey;
    private string $model;
    private string $endpoint;
    private bool $enabled;
    private int $timeout;
    private string $sourceLanguage;
    /** @var array<string, string> */
    private array $availableLanguages;

    /**
     * @param array{apiKey: string, model: string, endpoint: string, enabled: bool, timeout: int, sourceLanguage: string} $openaiConfig
     * @param array{default: string, available: array<string,string>} $i18nConfig
     */
    public function __construct(
        private OpenAiConfig $openaiConfig,
        private I18nConfig $i18nConfig,
        private string $cacheDir // Tento string stále musíte nabindovat v services.yaml
    )
    {
        $this->apiKey = $openaiConfig['apiKey'] ?? '';
        $this->model = $openaiConfig['model'] ?? 'gpt-4o-mini';
        $this->endpoint = $openaiConfig['endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
        $this->enabled = (bool)($openaiConfig['enabled'] ?? false);
        $this->timeout = (int)($openaiConfig['timeout'] ?? 30);
        $this->sourceLanguage = $openaiConfig['sourceLanguage'] ?? 'cs';
        $this->availableLanguages = $i18nConfig['available'] ?? ['cs' => 'Čeština'];

        $storage = new FileStorage($cacheDir);
        $this->cache = new Cache($storage, 'translations');
    }

    /**
     * Vrací true pokud je služba připravená překládat.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey) && !str_starts_with($this->apiKey, 'sk-PROJ-xxxxx');
    }

    /**
     * @return array<string,string> Jazykové páry ['cs' => 'Čeština', ...]
     */
    public function getAvailableLanguages(): array
    {
        return $this->availableLanguages;
    }

    /**
     * Přeloží text do cílového jazyka. Výsledek se kešuje.
     *
     * @param string $text         Zdrojový text
     * @param string $targetLang   Cílový ISO kód jazyka (cs/en/sk/de/pl/uk)
     * @param string|null $sourceLang Zdrojový jazyk; pokud null, použije se defaultní
     * @param bool $isHtml         Je vstup HTML? (zachovat značky)
     * @return string Přeložený text, nebo původní text při chybě/vypnutí
     */
    public function translate(string $text, string $targetLang, ?string $sourceLang = null, bool $isHtml = false): string
    {
        // Služba je vypnutá -> vracíme originál
        if (!$this->isEnabled()) {
            return $text;
        }

        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $sourceLang = $sourceLang ?? $this->sourceLanguage;

        // Cílový = zdrojový -> není co překládat
        if ($sourceLang === $targetLang) {
            return $text;
        }

        // Cílový jazyk není v podporovaných
        if (!isset($this->availableLanguages[$targetLang])) {
            return $text;
        }

        $cacheKey = $this->buildCacheKey($text, $sourceLang, $targetLang, $isHtml);
        $cached = $this->cache->load($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $translated = $this->callOpenAI($text, $sourceLang, $targetLang, $isHtml);
            // Uložíme na 30 dní - překlady se nemění
            $this->cache->save($cacheKey, $translated, [
                Cache::Expire => '30 days',
            ]);
            return $translated;
        } catch (\Throwable $e) {
            Debugger::log('TranslationService error: ' . $e->getMessage(), 'openai');
            return $text; // fallback na originál
        }
    }

    /**
     * Hromadný překlad více textů jedním voláním (úspora tokenů).
     *
     * @param array<int|string, string> $texts   Pole textů (klíče se zachovají)
     * @param string $targetLang
     * @param string|null $sourceLang
     * @return array<int|string, string>
     */
    public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null): array
    {
        $result = [];
        foreach ($texts as $key => $text) {
            $result[$key] = $this->translate($text, $targetLang, $sourceLang);
        }
        return $result;
    }

    /**
     * Detekuje pravděpodobný jazyk textu - velmi jednoduchá heuristika.
     * Pro spolehlivou detekci použijte OpenAI endpoint, ale stojí tokeny.
     */
    public function detectLanguage(string $text): string
    {
        $text = mb_strtolower($text);

        // Česká diakritika
        if (preg_match('/[ěščřžýáíéúůťďň]/u', $text)) {
            return 'cs';
        }
        // Slovenská specifika
        if (preg_match('/[ľĺôŕ]/u', $text)) {
            return 'sk';
        }
        // Polská specifika
        if (preg_match('/[ąęłńóśźż]/u', $text)) {
            return 'pl';
        }
        // Německá specifika
        if (preg_match('/[äöüß]/u', $text)) {
            return 'de';
        }
        // Ukrajinská azbuka
        if (preg_match('/[а-яії]/u', $text)) {
            return 'uk';
        }

        return 'en'; // fallback
    }

    /**
     * Invaliduje cache překladů (např. když se zdrojový text změní).
     */
    public function invalidateTranslation(string $text, string $sourceLang, string $targetLang, bool $isHtml = false): void
    {
        $cacheKey = $this->buildCacheKey($text, $sourceLang, $targetLang, $isHtml);
        $this->cache->remove($cacheKey);
    }

    /**
     * Invaliduje celou cache (admin funkce).
     */
    public function clearAllCache(): void
    {
        $this->cache->clean([Cache::All => true]);
    }

    // ===================================================================
    // interní metody
    // ===================================================================

    private function buildCacheKey(string $text, string $source, string $target, bool $isHtml): string
    {
        return sprintf(
            '%s-%s-%s-%s',
            $source,
            $target,
            $isHtml ? 'html' : 'plain',
            md5($text)
        );
    }

    /**
     * @throws \RuntimeException
     */
    private function callOpenAI(string $text, string $sourceLang, string $targetLang, bool $isHtml): string
    {
        $sourceName = $this->availableLanguages[$sourceLang] ?? $sourceLang;
        $targetName = $this->availableLanguages[$targetLang] ?? $targetLang;

        $systemPrompt = sprintf(
            'You are a professional translator. Translate from %s to %s. ' .
            'Return ONLY the translated text, nothing else - no quotes, no comments, no explanations. ' .
            '%s' .
            'Keep placeholders like %%azyl%%, %%adopter%%, {name}, {$variable} intact.',
            $sourceName,
            $targetName,
            $isHtml
                ? 'The input contains HTML tags - preserve them exactly as they are, only translate the text content. '
                : ''
        );

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => 0.3, // konzistentní překlady
        ];

        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            throw new \RuntimeException('Nelze inicializovat cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('cURL selhal: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('OpenAI API vrátila HTTP ' . $httpCode . ': ' . $response);
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException('Neočekávaná odpověď OpenAI: ' . $response);
        }

        return trim($data['choices'][0]['message']['content']);
    }
}
