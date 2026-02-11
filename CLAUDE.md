# Address Normalizer — Specyfikacja projektu dla Claude Code

## Cel dokumentu

Ten dokument jest specyfikacją techniczną projektu `address-normalizer` — lekkiego serwisu API do normalizacji i czyszczenia adresów pocztowych przy użyciu AI. Dokument służy jako instrukcja dla Claude Code do zbudowania projektu od podstaw.

---

## 1. Opis projektu

### Problem
Sklepy internetowe otrzymują adresy dostawy z błędami: nazwy firm w polu "miasto", komentarze dla kuriera w polu "adres", dane w niewłaściwych polach. Te adresy trafiają na listy kurierskie i generują zwroty, opóźnienia, koszty.

### Rozwiązanie
Lekki serwis REST API (Laravel 11) który przyjmuje surowy adres, czyści go przez pipeline (regex pre-clean → cache → opcjonalnie Libpostal → AI LLM), i zwraca znormalizowany, uporządkowany adres w ustandaryzowanym formacie JSON.

### Zakres
- Wszystkie kraje europejskie (EU + UK, NO, CH, UA itd.)
- Dwa wymienne providery AI: **OpenAI (GPT-4o-mini)** i **Anthropic (Claude Sonnet)**
- Opcjonalnie: Libpostal jako dodatkowa warstwa parsowania (osobny mikroserwis Docker)
- Prosta autoryzacja API key (Bearer token via Laravel Sanctum)
- Brak dashboardów — system jest częścią większej infrastruktury
- Agresywny cache (Redis) dla powtarzających się adresów

---

## 2. Stack technologiczny

| Komponent | Technologia |
|-----------|-------------|
| Framework | Laravel 11 |
| PHP | 8.2+ |
| Cache | Redis |
| Baza danych | MySQL 8 |
| Queue | Redis (Laravel Queue) |
| Auth | Laravel Sanctum (Bearer Token) |
| AI Provider 1 | OpenAI API (GPT-4o-mini) — structured output / JSON mode |
| AI Provider 2 | Anthropic API (Claude Sonnet) |
| Libpostal (opcja) | Osobny mikroserwis (FastAPI/Python, Docker) — komunikacja po HTTP |
| Testy | PHPUnit + Pest |

---

## 3. Architektura — Pipeline normalizacji

```
Request
  │
  ▼
[Auth Middleware] → 401 jeśli brak/niepoprawny token
  │
  ▼
[Rate Limiter] → 429 jeśli przekroczony limit
  │
  ▼
[Request Validation] → 422 jeśli niepoprawne dane
  │
  ▼
[Pre-Cleaner] → Regex: usuwa telefony, emaile, emotikony, komentarze kurierskie
  │
  ▼
[Cache Lookup] → Redis: hash(normalized_input) → HIT → Response
  │                                              → MISS ↓
  ▼
[Libpostal] (opcjonalnie, jeśli skonfigurowany) → wstępne parsowanie
  │
  ▼
[AI Normalizer] → LLM API call (OpenAI lub Anthropic, konfigurowalne)
  │
  ▼
[Post-Validator] → walidacja kodu pocztowego vs kraj, format sprawdzenie
  │
  ▼
[Cache Store] → Redis: zapisz wynik, TTL 30 dni
  │
  ▼
[Log] → zapisz request do DB (retencja 30 dni)
  │
  ▼
Response (JSON)
```

---

## 4. Struktura katalogów Laravel

```
address-normalizer/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── CleanExpiredLogs.php        # Artisan: czyszczenie logów >30 dni
│   │       └── ResetMonthlyUsage.php       # Artisan: reset limitów 1. dnia miesiąca
│   ├── Contracts/
│   │   ├── LlmProviderInterface.php        # Interfejs dla providerów AI
│   │   └── AddressParserInterface.php      # Interfejs dla parserów (Libpostal)
│   ├── DTOs/
│   │   ├── RawAddressInput.php             # DTO wejściowy
│   │   └── NormalizedAddress.php           # DTO wyjściowy
│   ├── Enums/
│   │   └── CountryCode.php                 # Enum krajów europejskich z formatami
│   ├── Exceptions/
│   │   └── NormalizationException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       └── AddressController.php   # normalize, batch, status
│   │   ├── Middleware/
│   │   │   └── TrackApiUsage.php           # Middleware zliczający requesty
│   │   └── Requests/
│   │       ├── NormalizeAddressRequest.php
│   │       └── BatchNormalizeRequest.php
│   ├── Models/
│   │   ├── ApiClient.php                   # Klient API (firma/użytkownik)
│   │   └── RequestLog.php                  # Log requestów
│   ├── Providers/
│   │   └── AddressNormalizerServiceProvider.php
│   └── Services/
│       ├── AddressNormalizer.php            # Główny orchestrator pipeline
│       ├── PreCleaner.php                   # Regex pre-processing
│       ├── PostValidator.php                # Walidacja wynikowa
│       ├── CacheManager.php                 # Redis cache logic
│       ├── LlmProviders/
│       │   ├── OpenAiProvider.php           # Implementacja OpenAI
│       │   └── AnthropicProvider.php        # Implementacja Anthropic
│       └── Parsers/
│           └── LibpostalClient.php          # HTTP client do Libpostal API
├── config/
│   └── normalizer.php                      # Konfiguracja: provider, limity, TTL, Libpostal URL
├── database/
│   └── migrations/
│       ├── create_api_clients_table.php
│       └── create_request_logs_table.php
├── routes/
│   └── api.php
├── tests/
│   ├── Feature/
│   │   ├── NormalizeEndpointTest.php
│   │   └── BatchEndpointTest.php
│   └── Unit/
│       ├── PreCleanerTest.php
│       ├── OpenAiProviderTest.php
│       ├── AnthropicProviderTest.php
│       └── PostValidatorTest.php
└── .env.example
```

---

## 5. API Endpoints

### 5.1 POST /api/v1/normalize

Normalizuje pojedynczy adres.

**Request:**
```json
{
  "country": "PL",
  "postal_code": "00-001",
  "city": "Warszawa FHU Jan Kowalski",
  "address": "ul. Marszałkowska 1/2 proszę dzwonić przed dostawą",
  "full_name": "Jan Kowalski"
}
```

Pola `country`, `city`, `address` są wymagane. Reszta opcjonalna.
Pole `full_name` służy do detekcji — jeśli imię/nazwisko pojawia się w polu city lub address, system wie że to nie nazwa firmy.

**Response 200:**
```json
{
  "status": "ok",
  "confidence": 0.95,
  "source": "ai",
  "data": {
    "country_code": "PL",
    "region": "mazowieckie",
    "postal_code": "00-001",
    "city": "Warszawa",
    "street": "Marszałkowska",
    "house_number": "1",
    "apartment_number": "2",
    "company_name": "FHU Jan Kowalski",
    "formatted": "Marszałkowska 1/2, 00-001 Warszawa"
  },
  "removed_noise": [
    "proszę dzwonić przed dostawą"
  ]
}
```

Pole `source` może mieć wartości: `cache`, `ai`, `libpostal+ai`.
Pole `confidence` to float 0.0–1.0 zwracany przez LLM.

**Response 422 (validation error):**
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "country": ["The country field is required."]
  }
}
```

### 5.2 POST /api/v1/normalize/batch

Normalizuje do 50 adresów jednocześnie.

**Request:**
```json
{
  "addresses": [
    {
      "id": "order_12345",
      "country": "PL",
      "postal_code": "00-001",
      "city": "Warszawa",
      "address": "Marszałkowska 1/2"
    },
    {
      "id": "order_12346",
      "country": "CZ",
      "postal_code": "110 00",
      "city": "Praha",
      "address": "Vodičkova 681/14"
    }
  ]
}
```

Pole `id` jest opcjonalne — jeśli podane, jest zwracane w odpowiedzi, ułatwia mapowanie wyników.

**Response 200:**
```json
{
  "status": "ok",
  "results": [
    {
      "id": "order_12345",
      "status": "ok",
      "confidence": 0.98,
      "source": "cache",
      "data": { ... }
    },
    {
      "id": "order_12346",
      "status": "ok",
      "confidence": 0.95,
      "source": "ai",
      "data": { ... }
    }
  ],
  "stats": {
    "total": 2,
    "from_cache": 1,
    "from_ai": 1,
    "failed": 0
  }
}
```

### 5.3 GET /api/v1/status

Health check + statystyki klienta.

**Response 200:**
```json
{
  "status": "ok",
  "client": "sportello",
  "plan_limit": 10000,
  "used_this_month": 1234,
  "remaining": 8766,
  "cache_hit_rate": 0.72,
  "active_provider": "openai"
}
```

---

## 6. Modele i migracje

### 6.1 api_clients

```php
Schema::create('api_clients', function (Blueprint $table) {
    $table->id();
    $table->string('name');                          // Nazwa klienta (np. "sportello")
    $table->string('api_key', 64)->unique();         // Bearer token (hash)
    $table->string('api_key_plain', 64)->nullable(); // Pokazywane tylko przy tworzeniu
    $table->unsignedInteger('monthly_limit')->default(1000);
    $table->unsignedInteger('current_month_usage')->default(0);
    $table->boolean('is_active')->default(true);
    $table->string('preferred_provider')->default('openai'); // openai | anthropic
    $table->json('settings')->nullable();            // Dodatkowe ustawienia per klient
    $table->timestamps();
});
```

### 6.2 request_logs

```php
Schema::create('request_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
    $table->string('source', 20);                    // cache | ai | libpostal+ai
    $table->string('provider', 20)->nullable();      // openai | anthropic
    $table->json('raw_input');                        // Surowe dane wejściowe
    $table->json('normalized_output')->nullable();    // Wynik normalizacji
    $table->float('confidence')->nullable();
    $table->unsignedInteger('processing_time_ms');
    $table->boolean('is_successful')->default(true);
    $table->text('error_message')->nullable();
    $table->string('country_code', 2)->nullable();   // Dla statystyk
    $table->timestamp('created_at');

    $table->index(['api_client_id', 'created_at']);
    $table->index('created_at');                     // Dla cleanup jobu
});
```

---

## 7. Kluczowe klasy — szczegóły implementacji

### 7.1 LlmProviderInterface

```php
<?php

namespace App\Contracts;

use App\DTOs\RawAddressInput;
use App\DTOs\NormalizedAddress;

interface LlmProviderInterface
{
    /**
     * Normalizuj pojedynczy adres przez AI.
     *
     * @return NormalizedAddress
     * @throws \App\Exceptions\NormalizationException
     */
    public function normalize(RawAddressInput $input): NormalizedAddress;

    /**
     * Normalizuj batch adresów (jeśli provider wspiera).
     * Domyślna implementacja: iteruje po kolei.
     *
     * @param RawAddressInput[] $inputs
     * @return NormalizedAddress[]
     */
    public function normalizeBatch(array $inputs): array;

    /**
     * Nazwa providera do logów.
     */
    public function name(): string;
}
```

### 7.2 OpenAiProvider — kluczowe elementy

- Użyj `response_format` z JSON Schema (structured outputs) — wymusza poprawny JSON
- Model: `gpt-4o-mini` (konfigurowalny w `config/normalizer.php`)
- Timeout: 10 sekund, retry: 2 razy z backoff
- W batch mode: wyślij wszystkie adresy w jednym prompcie (do 50), parsuj tablicę wyników
- HTTP client: użyj `Http::withToken()` z Laravel

Przykładowy payload do OpenAI:
```json
{
  "model": "gpt-4o-mini",
  "response_format": {
    "type": "json_schema",
    "json_schema": {
      "name": "normalized_address",
      "strict": true,
      "schema": { ... }
    }
  },
  "messages": [
    {"role": "system", "content": "<<SYSTEM_PROMPT>>"},
    {"role": "user", "content": "<<ADDRESS_DATA>>"}
  ]
}
```

### 7.3 AnthropicProvider — kluczowe elementy

- Model: `claude-sonnet-4-20250514` (konfigurowalny)
- Użyj system prompt + wymuś JSON w prompcie (Anthropic nie ma native JSON mode jak OpenAI, ale dobrze respektuje instrukcje)
- Timeout: 10 sekund, retry: 2 razy
- W batch mode: tak samo jak OpenAI — jeden prompt z wieloma adresami
- HTTP client: `Http::withHeaders(['x-api-key' => ..., 'anthropic-version' => '2023-06-01'])`

### 7.4 System prompt (wspólny dla obu providerów)

```
Jesteś specjalistycznym parserem adresów pocztowych dla krajów europejskich.

## Zadanie
Otrzymujesz surowe dane adresowe, które mogą zawierać błędy:
- Dane w złych polach (nazwa firmy w polu miasto, komentarz w polu adres)
- Komentarze dla kuriera
- Numery telefonów, adresy email
- Duplikacje informacji

## Zasady parsowania
1. Rozpoznaj kraj po kodzie ISO lub formacie kodu pocztowego:
   - XX-XXX = Polska (PL)
   - XXX XX = Czechy (CZ)
   - XXXXX = Niemcy (DE), Francja (FR), Włochy (IT) — rozróżniaj po kontekście
   - XXXX = Holandia (NL), Belgia (BE), Szwajcaria (CH), Austria (AT)
   - XX-XXXX = Portugalia (PT)
   - I inne europejskie formaty

2. Nazwy firm rozpoznaj po słowach kluczowych:
   PL: sp. z o.o., s.a., s.c., FHU, PHU, PPHU, P.P.H.U., firma, zakład
   CZ: s.r.o., a.s., v.o.s., k.s.
   DE: GmbH, AG, e.V., OHG, KG, UG
   SK: s.r.o., a.s.
   FR: SARL, SAS, SA, EURL
   UK: Ltd, LLP, PLC
   IT: S.r.l., S.p.A., S.a.s.
   ES: S.L., S.A., S.L.U.
   NL: B.V., N.V.
   Generyczne: Inc, LLC, GmbH, Corp

3. Komentarze/noise → wydziel do removed_noise:
   - "proszę dzwonić", "zadzwonić przed", "uwaga", "brama od", "kod do bramy",
     "piętro", "klatka", "domofon", numery telefonów, emaile
   - Ale ZACHOWAJ informacje o piętrze/klatce jeśli pasują jako doprecyzowanie adresu

4. Ulice:
   - Usuń prefiksy: "ul.", "ul ", "ulica ", "al.", "aleja ", "os.", "osiedle ", "pl.", "plac "
   - Zachowaj: "Aleja" jeśli jest częścią nazwy właściwej (np. "Aleja Jana Pawła II")
   - Rozdziel numer domu od numeru mieszkania: "15/4" → house_number: "15", apartment: "4"
   - "15 m. 4", "15 lok. 4", "15 m4" → house_number: "15", apartment: "4"

5. Region/województwo:
   - PL: województwo (np. "mazowieckie", "wielkopolskie")
   - CZ: kraj (np. "Hlavní město Praha", "Jihomoravský")
   - DE: Bundesland (np. "Bayern", "Nordrhein-Westfalen")
   - Jeśli nie możesz określić z danych, zwróć null

6. Confidence:
   - 1.0: adres jednoznaczny, wszystkie pola jasne
   - 0.8-0.99: wysokie prawdopodobieństwo poprawności, drobne wątpliwości
   - 0.5-0.79: niepewność, brakujące dane lub niejednoznaczność
   - <0.5: poważne wątpliwości, adres może być niepoprawny

## Format wyjściowy
Zwróć WYŁĄCZNIE prawidłowy JSON (bez markdown, bez komentarzy):
{
  "country_code": "XX",
  "region": "string lub null",
  "postal_code": "string",
  "city": "string",
  "street": "string lub null",
  "house_number": "string lub null",
  "apartment_number": "string lub null",
  "company_name": "string lub null",
  "removed_noise": ["array", "of", "strings"],
  "confidence": 0.95,
  "formatted": "Street HouseNr/Apt, PostalCode City"
}

## Dla batch (wiele adresów)
Zwróć tablicę JSON obiektów w tej samej kolejności co input.
```

### 7.5 PreCleaner

```php
class PreCleaner
{
    /**
     * Wstępne czyszczenie regexem PRZED wysłaniem do AI.
     * Cel: zmniejszyć liczbę tokenów i pomóc AI.
     */
    public function clean(RawAddressInput $input): RawAddressInput
    {
        // Dla każdego pola tekstowego:
        // 1. Usuń podwójne/potrójne spacje → pojedyncza spacja
        // 2. Usuń emotikony (unicode ranges)
        // 3. Usuń numery telefonów: /(\+?\d{2,3}[\s.-]?)?\d{3}[\s.-]?\d{3}[\s.-]?\d{3}/
        // 4. Usuń adresy email: /[\w.-]+@[\w.-]+\.\w+/
        // 5. Trim każde pole
        // 6. Zamień znaki specjalne: tabulatory, \r\n → spacja

        // NIE usuwaj nazw firm, komentarzy kurierskich (to robi AI)
        // PreCleaner robi tylko "tanie" operacje
    }
}
```

### 7.6 CacheManager

```php
class CacheManager
{
    /**
     * Klucz cache: md5(strtolower(trim(country|postal|city|address)))
     * Prefix: "addr:"
     * TTL: 30 dni (konfigurowalne)
     *
     * Cache jest globalny (współdzielony między klientami API).
     * Przed zapisem do cache: sanityzuj dane osobowe (usuń full_name z klucza).
     */

    public function lookup(RawAddressInput $input): ?NormalizedAddress;
    public function store(RawAddressInput $input, NormalizedAddress $result): void;
    public function generateKey(RawAddressInput $input): string;
}
```

### 7.7 PostValidator

```php
class PostValidator
{
    /**
     * Walidacja wyników AI:
     *
     * 1. Sprawdź format kodu pocztowego vs country_code:
     *    - PL: /^\d{2}-\d{3}$/
     *    - CZ, SK: /^\d{3}\s?\d{2}$/
     *    - DE, FR, IT, ES: /^\d{5}$/
     *    - UK: /^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i
     *    - NL: /^\d{4}\s?[A-Z]{2}$/i
     *    - AT, BE, CH, DK, NO, HU, LU, SI: /^\d{4}$/
     *    - SE: /^\d{3}\s?\d{2}$/
     *    - PT: /^\d{4}-\d{3}$/
     *    - IE: /^[A-Z\d]{3}\s?[A-Z\d]{4}$/i (Eircode)
     *    - FI: /^\d{5}$/
     *    - RO: /^\d{6}$/
     *    - GR: /^\d{3}\s?\d{2}$/
     *    - HR: /^\d{5}$/
     *    - BG: /^\d{4}$/
     *    - LT: /^LT-\d{5}$/
     *    - LV: /^LV-\d{4}$/
     *    - EE: /^\d{5}$/
     *    - CY: /^\d{4}$/
     *    - MT: /^[A-Z]{3}\s?\d{4}$/i
     *
     * 2. Sprawdź czy country_code jest prawidłowy (ISO 3166-1 alpha-2, europejski)
     *
     * 3. Sprawdź czy house_number wygląda sensownie (nie jest 5-cyfrowym kodem pocztowym)
     *
     * 4. Jeśli walidacja się nie zgadza → obniż confidence o 0.2
     */
}
```

---

## 8. Konfiguracja (config/normalizer.php)

```php
<?php

return [
    // Aktywny provider AI: 'openai' lub 'anthropic'
    'default_provider' => env('NORMALIZER_PROVIDER', 'openai'),

    // OpenAI
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => env('OPENAI_TIMEOUT', 10),
        'max_retries' => env('OPENAI_MAX_RETRIES', 2),
    ],

    // Anthropic
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'timeout' => env('ANTHROPIC_TIMEOUT', 10),
        'max_retries' => env('ANTHROPIC_MAX_RETRIES', 2),
    ],

    // Libpostal (opcjonalny, osobny mikroserwis)
    'libpostal' => [
        'enabled' => env('LIBPOSTAL_ENABLED', false),
        'url' => env('LIBPOSTAL_URL', 'http://localhost:5000'),
        'timeout' => env('LIBPOSTAL_TIMEOUT', 3),
    ],

    // Cache
    'cache' => [
        'enabled' => env('NORMALIZER_CACHE_ENABLED', true),
        'ttl_days' => env('NORMALIZER_CACHE_TTL_DAYS', 30),
        'prefix' => 'addr:',
    ],

    // Limity
    'batch_max_size' => 50,

    // Logi
    'log_retention_days' => 30,

    // Walidacja postcodes
    'validate_postal_codes' => env('NORMALIZER_VALIDATE_POSTCODES', true),
];
```

---

## 9. Routing (routes/api.php)

```php
<?php

use App\Http\Controllers\Api\AddressController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'track.api.usage'])->group(function () {
    Route::post('/normalize', [AddressController::class, 'normalize']);
    Route::post('/normalize/batch', [AddressController::class, 'batch']);
    Route::get('/status', [AddressController::class, 'status']);
});
```

---

## 10. Autentykacja

Użyj Laravel Sanctum w trybie API token (nie SPA).

### Tworzenie klienta API (Artisan command):
```bash
php artisan api-client:create "sportello" --limit=10000 --provider=openai
```

To powinno:
1. Wygenerować losowy 64-znakowy token
2. Zapisać hash tokenu w `api_clients.api_key`
3. Wyświetlić plain token JEDNORAZOWO w konsoli
4. Ustawić limit i preferowanego providera

### Użycie:
```
Authorization: Bearer {plain_token}
```

### Artisan commands do zarządzania:
```bash
php artisan api-client:create "nazwa" --limit=5000 --provider=anthropic
php artisan api-client:list
php artisan api-client:rotate-key {id}        # Generuj nowy token
php artisan api-client:deactivate {id}
php artisan api-client:set-limit {id} 20000
```

---

## 11. Scheduler (Console Kernel / bootstrap/app.php)

```php
// Codziennie o 2:00 — usuwaj logi starsze niż 30 dni
$schedule->command('logs:clean-expired')->dailyAt('02:00');

// 1. dnia miesiąca o 00:01 — resetuj liczniki użycia
$schedule->command('usage:reset-monthly')->monthlyOn(1, '00:01');
```

---

## 12. Middleware TrackApiUsage

```php
/**
 * Middleware które:
 * 1. Sprawdza czy klient nie przekroczył monthly_limit
 * 2. Inkrementuje current_month_usage po pomyślnym requeście
 * 3. Dla batch: inkrementuje o liczbę adresów w batchu
 * 4. Jeśli limit przekroczony → 429 Too Many Requests
 */
```

---

## 13. Obsługa błędów i fallback

### Fallback między providerami
Jeśli główny provider (np. OpenAI) zwróci błąd lub timeout:
1. Pierwsza próba: retry (max 2 razy z exponential backoff)
2. Jeśli nadal nie działa: automatyczny fallback na drugi provider
3. Jeśli oba nie działają: zwróć 503 z informacją

```php
// W AddressNormalizer::normalize()
try {
    return $this->primaryProvider->normalize($input);
} catch (NormalizationException $e) {
    Log::warning("Primary provider failed, falling back", ['error' => $e->getMessage()]);
    return $this->fallbackProvider->normalize($input);
}
```

### Kody odpowiedzi HTTP
| Kod | Znaczenie |
|-----|-----------|
| 200 | Sukces |
| 401 | Brak/niepoprawny token |
| 422 | Błąd walidacji inputu |
| 429 | Przekroczony limit miesięczny lub rate limit |
| 503 | Oba providery AI niedostępne |

---

## 14. Testy — scenariusze

### Unit tests (PreCleaner):
```
- "Marszałkowska 1/2 tel. 500100200" → telefon usunięty
- "Warszawa  \t  " → "Warszawa" (whitespace cleanup)
- "ul. Piękna 5 jan@example.com" → email usunięty
- "Normal address 123" → bez zmian (nie psuje poprawnych danych)
```

### Unit tests (PostValidator):
```
- PL + "00-001" → valid
- PL + "00001" → invalid format
- CZ + "110 00" → valid
- DE + "10115" → valid
- UK + "SW1A 1AA" → valid
- country "XX" → invalid country
```

### Feature tests (API):
```
- POST /normalize bez tokena → 401
- POST /normalize z poprawnym tokenem i danymi → 200
- POST /normalize z brakującym polem country → 422
- POST /normalize/batch z 51 adresami → 422 (max 50)
- POST /normalize po przekroczeniu limitu → 429
- GET /status → 200 z poprawnymi statystykami
```

### Integration tests (z mockami AI):
```
- Adres z firmą w polu city → firma wydzielona do company_name
- Adres z komentarzem kurierskim → komentarz w removed_noise
- Adres cache HIT → source: "cache", brak wywołania AI
- Timeout AI → fallback na drugi provider
- Oba providery down → 503
```

### Przykładowe adresy testowe (edge cases):

```json
[
  {
    "name": "Firma w polu miasto (PL)",
    "input": {"country": "PL", "postal_code": "00-001", "city": "Warszawa FHU Kowalski", "address": "Marszałkowska 1/2"},
    "expected_city": "Warszawa",
    "expected_company": "FHU Kowalski"
  },
  {
    "name": "Komentarz kurierski (PL)",
    "input": {"country": "PL", "postal_code": "30-001", "city": "Kraków", "address": "ul. Długa 5 m. 3 proszę dzwonić 500100200"},
    "expected_street": "Długa",
    "expected_house": "5",
    "expected_apt": "3",
    "expected_noise": ["proszę dzwonić 500100200"]
  },
  {
    "name": "Czeski adres z s.r.o.",
    "input": {"country": "CZ", "postal_code": "110 00", "city": "Praha 1 TechSoft s.r.o.", "address": "Vodičkova 681/14"},
    "expected_city": "Praha 1",
    "expected_company": "TechSoft s.r.o.",
    "expected_house": "681/14"
  },
  {
    "name": "Niemiecki adres",
    "input": {"country": "DE", "postal_code": "10115", "city": "Berlin", "address": "Friedrichstraße 123"},
    "expected_street": "Friedrichstraße",
    "expected_house": "123",
    "expected_apt": null
  },
  {
    "name": "Bałagan totalny",
    "input": {"country": "PL", "postal_code": "", "city": "00-950 Warszawa", "address": "PHU Export-Import sp. z o.o. Al. Jerozolimskie 100/5 uwaga: brama od Hożej, kod 1234"},
    "expected_postal": "00-950",
    "expected_city": "Warszawa",
    "expected_street": "Aleje Jerozolimskie",
    "expected_house": "100",
    "expected_apt": "5",
    "expected_company": "PHU Export-Import sp. z o.o."
  },
  {
    "name": "Holenderski adres",
    "input": {"country": "NL", "postal_code": "1012 JS", "city": "Amsterdam", "address": "Damrak 1"},
    "expected_postal": "1012 JS"
  },
  {
    "name": "Słowacki adres",
    "input": {"country": "SK", "postal_code": "811 01", "city": "Bratislava MegaTrade s.r.o.", "address": "Obchodná 5"},
    "expected_city": "Bratislava",
    "expected_company": "MegaTrade s.r.o."
  }
]
```

---

## 15. .env.example

```env
APP_NAME=AddressNormalizer
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://postalcodes.lumengroup.eu

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=address_normalizer
DB_USERNAME=
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

# === Address Normalizer ===
NORMALIZER_PROVIDER=openai
NORMALIZER_CACHE_ENABLED=true
NORMALIZER_CACHE_TTL_DAYS=30
NORMALIZER_VALIDATE_POSTCODES=true

# OpenAI
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
OPENAI_TIMEOUT=10

# Anthropic
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-sonnet-4-20250514
ANTHROPIC_TIMEOUT=10

# Libpostal (opcjonalny)
LIBPOSTAL_ENABLED=false
LIBPOSTAL_URL=http://libpostal-vps:5000
LIBPOSTAL_TIMEOUT=3
```

---

## 16. Kolejność implementacji

1. **Fundament**: `laravel new address-normalizer`, konfiguracja, migracje, modele
2. **DTOs**: `RawAddressInput`, `NormalizedAddress` (Spatie DTOs lub plain PHP classes)
3. **Config**: `config/normalizer.php`
4. **PreCleaner**: regex cleaning + unit testy
5. **PostValidator**: walidacja kodów pocztowych + unit testy
6. **CacheManager**: Redis cache logic
7. **LlmProviderInterface** + **OpenAiProvider**: implementacja + testy z mockami
8. **AnthropicProvider**: implementacja + testy z mockami
9. **AddressNormalizer** (orchestrator): złożenie pipeline
10. **AddressController** + requesty + routing
11. **Sanctum auth** + TrackApiUsage middleware
12. **Artisan commands**: api-client:create, logs:clean-expired, usage:reset-monthly
13. **Feature testy API**
13.5**Swagger (l5-swagger)**: adnotacje OpenAPI na kontrolerze i request classes
14. **LibpostalClient** (opcjonalnie, jeśli serwis jest dostępny)

---

## 17. Wymagania niefunkcjonalne

- **Czas odpowiedzi**: <200ms dla cache hit, <3s dla AI call
- **Cache hit rate**: docelowo >60% po kilku tygodniach użycia
- **Dostępność**: fallback między providerami = zero downtime przy awarii jednego AI
- **RODO**: nie cachuj pełnych imion/nazwisk — sanityzuj przed zapisem do cache, logi retencja 30 dni
- **Bezpieczeństwo**: tokeny API hashowane w DB (SHA-256), HTTPS only w produkcji
- **Monitoring**: loguj czas przetwarzania, confidence, source do request_logs — umożliwi analizę jakości

---

## 18. Uwagi dla Claude Code

- Użyj PHP 8.2+ features: enums, readonly properties, named arguments, match expressions
- Preferuj Pest do testów (czytelniejsza składnia), ale PHPUnit też OK
- DTOs mogą być plain readonly classes (nie wymagamy Spatie jeśli nie chcesz dodatkowej zależności)
- Service Provider powinien rejestrować binding `LlmProviderInterface` na podstawie konfiguracji klienta (z `api_clients.preferred_provider`) lub globalnego `config('normalizer.default_provider')`
- W batch mode: najpierw sprawdź cache dla wszystkich adresów, potem wyślij tylko te bez cache hit do AI w jednym prompcie
- Zainstaluj minimum pakietów: `laravel/sanctum`, `predis/predis` (lub phpredis)
- NIE instaluj: Filament, Livewire, Inertia, ani żadnych frontend dependencies
- Projekt powinien działać jako pure API (bez web routes, bez blade views)
