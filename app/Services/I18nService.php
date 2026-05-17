<?php
declare(strict_types=1);

namespace App\Services;

use Nette\Http\Session;
use Nette\Http\SessionSection;
use Nette\Http\Request;

/**
 * Správa aktuálního jazyka uživatele.
 *
 * - Vybírá jazyk v pořadí: URL param 'lang' > session > cookie > Accept-Language > default
 * - Uchovává volbu v session
 * - Používá se v presenterech pro rozhodování kdy překládat
 */
class I18nService
{
    private SessionSection $session;
    private Request $httpRequest;
    /** @var array<string,string> */
    private array $availableLanguages;
    private string $defaultLanguage;

    /**
     * @param array{default: string, available: array<string,string>} $i18nConfig
     */
    public function __construct(array $i18nConfig, Session $session, Request $httpRequest)
    {
        $this->availableLanguages = $i18nConfig['available'] ?? ['cs' => 'Čeština'];
        $this->defaultLanguage = $i18nConfig['default'] ?? 'cs';
        $this->session = $session->getSection('vaz_i18n');
        $this->httpRequest = $httpRequest;
    }

    /**
     * Vrací aktuálně zvolený jazyk uživatele.
     */
    public function getCurrentLanguage(): string
    {
        // 1) URL parametr ?lang=xx má přednost (např. přepínač jazyků)
        $urlLang = $this->httpRequest->getQuery('lang');
        if (is_string($urlLang) && isset($this->availableLanguages[$urlLang])) {
            $this->setCurrentLanguage($urlLang);
            return $urlLang;
        }

        // 2) Session
        $sessLang = $this->session->get('language');
        if (is_string($sessLang) && isset($this->availableLanguages[$sessLang])) {
            return $sessLang;
        }

        // 3) Accept-Language z prohlížeče
        $browserLang = $this->detectFromBrowser();
        if ($browserLang !== null) {
            $this->setCurrentLanguage($browserLang);
            return $browserLang;
        }

        // 4) Default
        return $this->defaultLanguage;
    }

    public function setCurrentLanguage(string $language): void
    {
        if (!isset($this->availableLanguages[$language])) {
            return;
        }
        $this->session->set('language', $language);
    }

    public function getDefaultLanguage(): string
    {
        return $this->defaultLanguage;
    }

    /**
     * @return array<string,string>
     */
    public function getAvailableLanguages(): array
    {
        return $this->availableLanguages;
    }

    public function isDefaultLanguage(): bool
    {
        return $this->getCurrentLanguage() === $this->defaultLanguage;
    }

    /**
     * Detekce Accept-Language hlavičky - vrací první podporovaný jazyk.
     */
    private function detectFromBrowser(): ?string
    {
        $header = $this->httpRequest->getHeader('Accept-Language');
        if (empty($header)) {
            return null;
        }

        // např. "cs-CZ,cs;q=0.9,en;q=0.8"
        $parts = explode(',', $header);
        foreach ($parts as $part) {
            $code = trim(explode(';', $part)[0]);
            $short = substr($code, 0, 2);
            if (isset($this->availableLanguages[$short])) {
                return $short;
            }
        }

        return null;
    }
}
