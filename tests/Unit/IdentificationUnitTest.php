<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\SpeciesSynonym;
use App\Domain\Identification\Data\IdentificationInput;
use App\Domain\Identification\Data\RawCandidate;
use App\Domain\Identification\Exceptions\ProviderRateLimited;
use App\Domain\Identification\Exceptions\ProviderUnavailable;
use App\Domain\Identification\Providers\GeminiProvider;
use App\Domain\Identification\Services\CandidateMatcher;
use App\Domain\Identification\Services\ConfidenceScorer;
use App\Domain\Identification\Services\CostCalculator;
use App\Domain\Shared\Enums\ConfidenceBand;
use App\Domain\Shared\Enums\MatchMethod;
use App\Domain\Shared\Support\Geohash;
use App\Domain\Shared\Support\NameNormalizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentificationUnitTest extends TestCase
{
    public function test_name_normalisation(): void
    {
        $this->assertSame('toman', NameNormalizer::common('  Ikan TOMAN '));
        $this->assertSame('channa micropeltes', NameNormalizer::scientific('Channa micropeltes (Cuvier, 1831)'));
        $this->assertSame('lates calcarifer', NameNormalizer::scientific('Lates calcarifer Bloch 1790'));
    }

    public function test_matcher_exact_synonym_fuzzy_common_and_ambiguous(): void
    {
        $my = Country::byIso('MY')->id;
        $toman = $this->species('Channa micropeltes');
        SpeciesSynonym::create(['species_id' => $toman->id, 'scientific_name' => 'Ophicephalus micropeltes']);
        $m = app(CandidateMatcher::class);
        $m->flush();

        $this->assertSame(MatchMethod::ScientificExact, $m->match(new RawCandidate('Channa micropeltes', null, 0.9), $my)['method']);
        $this->assertSame(MatchMethod::Synonym, $m->match(new RawCandidate('Ophicephalus micropeltes', null, 0.9), $my)['method']);

        $fuzzy = $m->match(new RawCandidate('Channa micropeltis', null, 0.9), $my);
        $this->assertSame(MatchMethod::Fuzzy, $fuzzy['method']);
        $this->assertSame($toman->id, $fuzzy['species_id']);

        $this->assertSame(MatchMethod::CommonName, $m->match(new RawCandidate(null, 'Ikan Toman', 0.9), $my)['method']);
        $this->assertSame(MatchMethod::None, $m->match(new RawCandidate(null, 'Kerapu', 0.9), $my)['method'], 'ambiguous names never resolve');
    }

    public function test_scorer_bands(): void
    {
        $scorer = app(ConfidenceScorer::class);
        $row = fn ($id, $conf, $method = MatchMethod::ScientificExact, $penalty = 0.0) => [
            'species_id' => $id, 'method' => $method, 'match_score' => $method->baseScore(),
            'model_confidence' => $conf, 'geo_penalty' => $penalty, 'agreement' => 0.5,
        ];

        $this->assertSame(ConfidenceBand::High, $scorer->score([$row(1, 0.95), $row(2, 0.1)], false)['band']);
        $this->assertSame(ConfidenceBand::Medium, $scorer->score([$row(1, 0.6), $row(2, 0.5)], false)['band']);
        $this->assertNotSame(ConfidenceBand::High, $scorer->score([$row(1, 0.95, MatchMethod::Fuzzy), $row(2, 0.1)], false)['band'], 'weak matches cannot be high');
        $this->assertNotSame(ConfidenceBand::High, $scorer->score([$row(1, 0.95, MatchMethod::ScientificExact, 0.15), $row(2, 0.1)], false)['band'], 'not recorded in country');
        $this->assertSame(ConfidenceBand::Low, $scorer->score([], false)['band']);
    }

    public function test_cost_calculator(): void
    {
        $c = new CostCalculator;
        $this->assertSame(0, $c->micros('gemini-3.6-flash', 'free', 1500, 400));
        // 1500 × $1.50/M + 400 × $7.50/M = $0.00525 = 5250 micro-USD
        $this->assertSame(5250, $c->micros('gemini-3.6-flash', 'paid', 1500, 400));
    }

    public function test_geohash_matches_reference_value(): void
    {
        $this->assertSame('w283', Geohash::encode(3.139, 101.6869, 4)); // Kuala Lumpur
    }

    public function test_gemini_provider_parses_structured_output_and_maps_429(): void
    {
        $config = config('fishdex.ai.providers.gemini');
        $config['api_key'] = 'test-key';
        $provider = new GeminiProvider($config);
        $input = new IdentificationInput('jpeg-bytes', 'image/jpeg', 'Malaysia', 'Pahang');

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'candidates' => [['scientific_name' => 'Channa micropeltes', 'common_name' => 'Toman', 'confidence' => 0.88, 'visible_features' => ['dark band']]],
                    'flags' => ['low_quality', 'made_up_flag'],
                ])]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1300, 'candidatesTokenCount' => 120, 'thoughtsTokenCount' => 30],
            ])
            ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']], 429),
        ]);

        $result = $provider->identify($input);
        $this->assertSame('Channa micropeltes', $result->candidates[0]->scientificName);
        $this->assertSame(['low_quality'], $result->flags, 'unknown flags are dropped');
        $this->assertSame(150, $result->outputTokens, 'thinking tokens billed as output');

        Http::assertSent(fn ($req) => $req->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($req->url(), 'key=')
            && $req['generationConfig']['responseMimeType'] === 'application/json'
            && ! str_contains(json_encode($req->data()), 'rarity'));

        $this->expectException(ProviderRateLimited::class);
        $provider->identify($input);
    }

    public function test_overloaded_model_falls_back_to_the_next_model(): void
    {
        $config = config('fishdex.ai.providers.gemini');
        $config['api_key'] = 'test-key';
        $config['model'] = 'gemini-4-hydrogen';
        $config['fallback_models'] = ['gemini-3.8-flash'];
        $provider = new GeminiProvider($config);
        $input = new IdentificationInput('jpeg-bytes', 'image/jpeg', 'Malaysia', null);

        $ok = ['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'candidates' => [['scientific_name' => 'Channa striata', 'common_name' => 'Haruan', 'confidence' => 0.9, 'visible_features' => []]],
            'flags' => [],
        ])]]], 'finishReason' => 'STOP']]];

        Http::fake([
            '*/models/gemini-4-hydrogen:*' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'This model is currently experiencing high demand.']], 503),
            '*/models/gemini-3.8-flash:*' => Http::response($ok),
        ]);

        $result = $provider->identify($input);
        $this->assertSame('gemini-3.8-flash', $result->model, 'attempt is logged against the model that answered');
        $this->assertSame('Channa striata', $result->candidates[0]->scientificName);
        Http::assertSentCount(2);
    }

    public function test_when_every_model_is_overloaded_the_last_error_is_raised(): void
    {
        $config = config('fishdex.ai.providers.gemini');
        $config['api_key'] = 'test-key';
        $config['model'] = 'gemini-4-hydrogen';
        $config['fallback_models'] = ['gemini-3.8-flash'];
        $provider = new GeminiProvider($config);
        $input = new IdentificationInput('jpeg-bytes', 'image/jpeg', 'Malaysia', null);

        Http::fake(['*' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'busy']], 503)]);
        try {
            $provider->identify($input);
            $this->fail('expected ProviderUnavailable');
        } catch (ProviderUnavailable $e) {
            $this->assertSame(503, $e->httpStatus);
        }
        Http::assertSentCount(2);
    }

    public function test_rate_limit_is_not_retried_on_another_model(): void
    {
        $config = config('fishdex.ai.providers.gemini');
        $config['api_key'] = 'test-key';
        $config['fallback_models'] = ['gemini-3.8-flash'];
        $provider = new GeminiProvider($config);

        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota']], 429)]);
        $this->expectException(ProviderRateLimited::class);
        try {
            $provider->identify(new IdentificationInput('jpeg-bytes', 'image/jpeg', 'Malaysia', null));
        } finally {
            Http::assertSentCount(1);
        }
    }
}
