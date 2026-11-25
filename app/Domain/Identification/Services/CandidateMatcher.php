<?php

namespace App\Domain\Identification\Services;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\SpeciesName;
use App\Domain\Catalogue\Models\SpeciesSynonym;
use App\Domain\Identification\Data\RawCandidate;
use App\Domain\Shared\Enums\MatchMethod;
use App\Domain\Shared\Support\NameNormalizer;

/**
 * Maps AI-proposed names onto verified, published catalogue species (doc 08 §5).
 * Ambiguous common names (shared by several species) never resolve on their own.
 */
class CandidateMatcher
{
    /** @var array<int, array> */
    private array $indexes = [];

    /** @return array{species_id: ?int, method: MatchMethod, score: float} */
    public function match(RawCandidate $candidate, int $countryId, array $shortlistRefs = []): array
    {
        $index = $this->index($countryId);

        if ($candidate->shortlistRef !== null && isset($shortlistRefs[$candidate->shortlistRef])) {
            return $this->result($shortlistRefs[$candidate->shortlistRef], MatchMethod::ConstrainedPick);
        }

        if ($candidate->scientificName) {
            $sci = NameNormalizer::scientific($candidate->scientificName);

            if (isset($index['scientific'][$sci])) {
                return $this->result($index['scientific'][$sci], MatchMethod::ScientificExact);
            }
            if (isset($index['synonyms'][$sci])) {
                return $this->result($index['synonyms'][$sci], MatchMethod::Synonym);
            }
            if ($fuzzy = $this->fuzzy($sci, $index)) {
                return $fuzzy;
            }
        }

        if ($candidate->commonName) {
            $ids = $index['names'][NameNormalizer::common($candidate->commonName)] ?? [];
            if (count($ids) === 1) {
                return $this->result($ids[0], MatchMethod::CommonName);
            }
        }

        return ['species_id' => null, 'method' => MatchMethod::None, 'score' => 0.0];
    }

    public function flush(): void
    {
        $this->indexes = [];
    }

    private function fuzzy(string $sci, array $index): ?array
    {
        [$genus, $epithet] = array_pad(explode(' ', $sci, 2), 2, '');
        if ($epithet === '' || ! isset($index['genera'][$genus])) {
            return null;
        }

        $best = null;
        foreach ($index['genera'][$genus] as [$candidateEpithet, $speciesId]) {
            $distance = levenshtein($epithet, $candidateEpithet);
            if ($distance <= 2 && ($best === null || $distance < $best[0])) {
                $best = [$distance, $speciesId];
            }
        }

        if ($best === null) {
            return null;
        }

        return ['species_id' => $best[1], 'method' => MatchMethod::Fuzzy, 'score' => round(0.75 - 0.05 * $best[0], 4)];
    }

    private function result(int $speciesId, MatchMethod $method): array
    {
        return ['species_id' => $speciesId, 'method' => $method, 'score' => $method->baseScore()];
    }

    private function index(int $countryId): array
    {
        return $this->indexes[$countryId] ??= $this->build($countryId);
    }

    private function build(int $countryId): array
    {
        $index = ['scientific' => [], 'synonyms' => [], 'names' => [], 'genera' => []];

        $species = FishSpecies::query()->published()->get(['id', 'scientific_name']);
        $publishedIds = $species->pluck('id')->flip();

        foreach ($species as $s) {
            $sci = NameNormalizer::scientific($s->scientific_name);
            $index['scientific'][$sci] = $s->id;
            [$genus, $epithet] = array_pad(explode(' ', $sci, 2), 2, '');
            $index['genera'][$genus][] = [$epithet, $s->id];
        }

        SpeciesSynonym::query()->get(['species_id', 'normalized_name'])
            ->each(function ($syn) use (&$index, $publishedIds) {
                if ($publishedIds->has($syn->species_id)) {
                    $index['synonyms'][$syn->normalized_name] = $syn->species_id;
                }
            });

        SpeciesName::query()
            ->where('is_ambiguous', false)
            ->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $countryId))
            ->get(['species_id', 'normalized_name'])
            ->each(function ($n) use (&$index, $publishedIds) {
                if ($publishedIds->has($n->species_id)) {
                    $index['names'][$n->normalized_name] ??= [];
                    if (! in_array($n->species_id, $index['names'][$n->normalized_name], true)) {
                        $index['names'][$n->normalized_name][] = $n->species_id;
                    }
                }
            });

        return $index;
    }
}
