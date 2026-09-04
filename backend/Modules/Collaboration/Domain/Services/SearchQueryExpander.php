<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Services;

use Illuminate\Support\Str;

/**
 * Shared search-query normalization authority (TASK-ECOS-INTERNAL-
 * COLLABORATION-MYSQL-SEARCH-STEMMING-REMEDIATION-003-R1). MySQL's FULLTEXT
 * parser does no stemming, unlike the original PostgreSQL design's
 * to_tsvector('english', ...), so a plural query like "shipments" would no
 * longer match a stored singular "shipment" — a real, user-visible
 * regression. This closes that gap at the application layer instead, using
 * Laravel's own Str::singular()/Str::plural() (doctrine/inflector, already a
 * framework dependency — no new package): bounded, deterministic,
 * rule-based inflection, not an unbounded dictionary or a one-off mapping.
 *
 * The raw token is always kept; singular/plural alternates are added only
 * where the inflector actually produces something different, so words it
 * doesn't recognize as nominal plurals/singulars (verbs, already-invariant
 * nouns, arbitrary user text) simply pass through unchanged — this can only
 * ever widen a match set relative to searching the raw term alone, never
 * narrow it.
 *
 * Engine-agnostic by construction: expand() knows nothing about SQL or any
 * particular database. Both Collaboration search actions (messages, tasks)
 * share this one instance rather than each embedding their own inflection
 * logic.
 */
final class SearchQueryExpander
{
    /**
     * Split $rawQuery into word tokens and, for each, add its bounded
     * singular/plural alternate(s) where materially different. Order is
     * stable; duplicates (case-insensitive) are collapsed to their first
     * occurrence.
     *
     * @return list<string>
     */
    public function expand(string $rawQuery): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $rawQuery, $matches);

        $seen = [];
        $terms = [];

        foreach ($matches[0] as $word) {
            foreach ([$word, Str::singular($word), Str::plural($word)] as $variant) {
                $key = mb_strtolower($variant);

                if ($variant === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $terms[] = $variant;
            }
        }

        return $terms;
    }

    /**
     * expand(), joined into a single space-separated string. Every token
     * extracted by expand() is drawn only from `[\p{L}\p{N}]+` matches, so
     * this string can never contain any of MySQL BOOLEAN MODE's operator
     * characters (+ - " ( ) * ~ < > @) regardless of what the user typed —
     * safe to pass directly into `AGAINST(? IN BOOLEAN MODE)`, where
     * space-separated terms are an implicit OR. Falls back to $rawQuery
     * unchanged when it has no extractable word tokens (e.g. punctuation-only
     * input) rather than sending an empty predicate.
     */
    public function toBooleanQueryString(string $rawQuery): string
    {
        $terms = $this->expand($rawQuery);

        return $terms === [] ? $rawQuery : implode(' ', $terms);
    }
}
