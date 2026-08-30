<?php

declare(strict_types=1);

namespace Modules\Logistics\Geography\Domain\Services;

use Illuminate\Support\Facades\DB;

/**
 * Resolves an Order's free-text address to a canonical Logistics City.
 *
 * ┌─ WHY THIS LIVES IN GEOGRAPHY ────────────────────────────────────────────┐
 * │ `logistics_cities` and `logistics_city_aliases` are Geography's tables.   │
 * │ Distribution must not learn how to read them: it already owns exactly one │
 * │ geographic lookup — OrderZoneResolver, city -> zone — and a second        │
 * │ implementation of "what city is this?" living there would be the duplicate│
 * │ resolver the audit flagged. Text -> city is answered here; city -> zone is │
 * │ answered there. Neither knows the other's table.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THIS CLASS NEVER GUESSES. Matching is exact (case- and whitespace-insensitive)
 * against values the database already holds:
 *
 *   1. logistics_cities.name_en
 *   2. logistics_cities.name_ar
 *   3. logistics_city_aliases.alias   (the provider-alias table, whatever it holds)
 *
 * No fuzzy matching, no substring matching, no Levenshtein, no "closest" city.
 * A near-miss is reported as unresolved, because a silently mis-zoned Order is
 * worse than an Order the operator can see is unzoned and why.
 *
 * The Order's governorate text NARROWS the candidate set when it resolves to a
 * real governorate; it never widens it. If narrowing leaves nothing, the
 * un-narrowed match is used, so a wrong governorate string cannot hide a city
 * that matched unambiguously on its own.
 *
 * AMBIGUITY IS A FAILURE, NOT A COIN TOSS. Two cities matching the same text
 * yield `Ambiguous`, never the first row.
 */
final class OrderCityResolver
{
    /** Why a city could not be determined. `null` reason = resolved. */
    public const REASON_ADDRESS_INCOMPLETE = 'address_incomplete';

    public const REASON_CITY_NOT_RESOLVED = 'city_not_resolved';

    public const REASON_CITY_AMBIGUOUS = 'city_ambiguous';

    /**
     * Lazily-built lookup tables. Binding runs over a batch of Orders, so the
     * geography tables are read once per instance rather than once per Order.
     *
     * @var array<string, list<array{id:int, governorate_id:int|null}>>|null
     */
    private ?array $cityIndex = null;

    /** @var array<string, int>|null normalised governorate name => id */
    private ?array $governorateIndex = null;

    /**
     * Resolve one Order address.
     *
     * @return array{city_id: int|null, reason: string|null}
     */
    public function resolve(?string $cityText, ?string $governorateText = null): array
    {
        $key = $this->normalise($cityText);

        if ($key === '') {
            return ['city_id' => null, 'reason' => self::REASON_ADDRESS_INCOMPLETE];
        }

        $candidates = $this->cityIndex()[$key] ?? [];

        if ($candidates === []) {
            return ['city_id' => null, 'reason' => self::REASON_CITY_NOT_RESOLVED];
        }

        if (count($candidates) > 1) {
            // Narrow by governorate ONLY to break a tie. A governorate that
            // eliminates every candidate is treated as unhelpful rather than
            // authoritative — the city text already matched something real.
            $governorateId = $this->governorateId($governorateText);

            if ($governorateId !== null) {
                $narrowed = array_values(array_filter(
                    $candidates,
                    static fn (array $c): bool => $c['governorate_id'] === $governorateId,
                ));

                if ($narrowed !== []) {
                    $candidates = $narrowed;
                }
            }
        }

        if (count($candidates) > 1) {
            return ['city_id' => null, 'reason' => self::REASON_CITY_AMBIGUOUS];
        }

        return ['city_id' => $candidates[0]['id'], 'reason' => null];
    }

    /**
     * Case-, whitespace- and punctuation-tolerant key.
     *
     * Only collapses noise that is never semantic in a city name: surrounding
     * space, repeated inner space, and Arabic tatweel. It does NOT strip letters,
     * transliterate, or normalise hamza forms — those change which city a string
     * names, which is exactly the guess this class refuses to make.
     */
    private function normalise(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $v = str_replace("\u{0640}", '', $value);      // tatweel (kashida)
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;    // collapse inner whitespace
        $v = trim($v);

        return mb_strtolower($v, 'UTF-8');
    }

    /** @return array<string, list<array{id:int, governorate_id:int|null}>> */
    private function cityIndex(): array
    {
        if ($this->cityIndex !== null) {
            return $this->cityIndex;
        }

        /** @var array<string, list<array{id:int, governorate_id:int|null}>> $index */
        $index = [];

        $add = function (string $key, int $id, ?int $governorateId) use (&$index): void {
            if ($key === '') {
                return;
            }

            foreach ($index[$key] ?? [] as $existing) {
                // The same city reached by two names (name_en and an alias) is one
                // candidate, not two — otherwise every aliased city would look
                // ambiguous with itself.
                if ($existing['id'] === $id) {
                    return;
                }
            }

            $index[$key][] = ['id' => $id, 'governorate_id' => $governorateId];
        };

        $cities = DB::table('logistics_cities')
            ->select(['id', 'name_en', 'name_ar', 'governorate_id'])
            ->get();

        /** @var array<int, int|null> $governorateByCity */
        $governorateByCity = [];

        foreach ($cities as $city) {
            $id = (int) $city->id;
            $governorateId = $city->governorate_id === null ? null : (int) $city->governorate_id;
            $governorateByCity[$id] = $governorateId;

            $add($this->normalise($city->name_en), $id, $governorateId);
            $add($this->normalise($city->name_ar), $id, $governorateId);
        }

        // Aliases are the operator's extension point: a provider spelling can be
        // taught to the system as data instead of as code. The table is currently
        // empty, so this loop is a no-op today and correct the moment a row exists.
        $aliases = DB::table('logistics_city_aliases')
            ->select(['city_id', 'alias'])
            ->get();

        foreach ($aliases as $alias) {
            $cityId = (int) $alias->city_id;

            // An alias pointing at a city that no longer exists must not create a
            // phantom candidate.
            if (! array_key_exists($cityId, $governorateByCity)) {
                continue;
            }

            $add($this->normalise($alias->alias), $cityId, $governorateByCity[$cityId]);
        }

        return $this->cityIndex = $index;
    }

    private function governorateId(?string $text): ?int
    {
        $key = $this->normalise($text);

        if ($key === '') {
            return null;
        }

        if ($this->governorateIndex === null) {
            /** @var array<string, int> $index */
            $index = [];

            foreach (DB::table('logistics_governorates')->select(['id', 'name_en', 'name_ar'])->get() as $row) {
                foreach ([$row->name_en, $row->name_ar] as $name) {
                    $normalised = $this->normalise($name);
                    if ($normalised !== '') {
                        $index[$normalised] = (int) $row->id;
                    }
                }
            }

            $this->governorateIndex = $index;
        }

        return $this->governorateIndex[$key] ?? null;
    }
}
