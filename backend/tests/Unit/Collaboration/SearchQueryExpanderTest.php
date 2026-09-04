<?php

declare(strict_types=1);

namespace Tests\Unit\Collaboration;

use Modules\Collaboration\Domain\Services\SearchQueryExpander;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-MYSQL-SEARCH-STEMMING-REMEDIATION-003-R1.
 * Pure logic, no database — mirrors tests/Unit/IAM/PermissionNameTest.php's
 * pattern of extending PHPUnit's TestCase directly for a dependency-free
 * value/service class, rather than Tests\TestCase (which requires a live
 * database connection this class has nothing to do with).
 */
final class SearchQueryExpanderTest extends TestCase
{
    private SearchQueryExpander $expander;

    protected function setUp(): void
    {
        $this->expander = new SearchQueryExpander;
    }

    public function test_expand_always_includes_the_raw_token(): void
    {
        $this->assertContains('shipment', $this->expander->expand('shipment'));
    }

    public function test_expand_adds_the_singular_alternate_for_a_plural_query(): void
    {
        // The exact contract this remediation exists to satisfy: a plural
        // query must be able to match a stored singular word.
        $terms = $this->expander->expand('shipments');

        $this->assertContains('shipments', $terms);
        $this->assertContains('shipment', $terms);
    }

    public function test_expand_adds_the_plural_alternate_for_a_singular_query(): void
    {
        $terms = $this->expander->expand('shipment');

        $this->assertContains('shipment', $terms);
        $this->assertContains('shipments', $terms);
    }

    public function test_expand_preserves_original_casing_of_the_raw_token(): void
    {
        $this->assertContains('Shipments', $this->expander->expand('Shipments'));
    }

    public function test_expand_is_bounded_per_word(): void
    {
        // Raw + singular + plural, deduplicated — never more than 3 terms
        // for a single input word, whatever the inflector produces.
        $this->assertLessThanOrEqual(3, count($this->expander->expand('shipment')));
        $this->assertLessThanOrEqual(3, count($this->expander->expand('unrelatedxyz')));
    }

    public function test_expand_does_not_drop_words_the_inflector_does_not_recognize(): void
    {
        // "arrived" is a verb, not a pluralizable noun — the raw term must
        // still come through unchanged; whatever the inflector guesses as an
        // "alternate" is harmless (see class docblock: this can only widen a
        // match set, never narrow it), so it is deliberately not asserted on.
        $this->assertContains('arrived', $this->expander->expand('arrived'));
        $this->assertContains('unrelatedxyz', $this->expander->expand('unrelatedxyz'));
    }

    public function test_expand_handles_multi_word_queries(): void
    {
        $terms = $this->expander->expand('the shipments arrived');

        $this->assertContains('the', $terms);
        $this->assertContains('shipments', $terms);
        $this->assertContains('shipment', $terms);
        $this->assertContains('arrived', $terms);
    }

    public function test_expand_never_returns_a_boolean_mode_operator_character(): void
    {
        $terms = $this->expander->expand('C++ "quoted phrase" -exclude (grouped) *wild* ~fuzzy~ email@example.com');

        foreach ($terms as $term) {
            foreach (['+', '-', '"', '(', ')', '*', '~', '<', '>', '@'] as $operatorChar) {
                $this->assertStringNotContainsString($operatorChar, $term);
            }
        }
    }

    public function test_expand_of_empty_or_punctuation_only_query_is_empty(): void
    {
        $this->assertSame([], $this->expander->expand(''));
        $this->assertSame([], $this->expander->expand('!!!'));
    }

    public function test_to_boolean_query_string_puts_the_raw_term_first(): void
    {
        $this->assertStringStartsWith('shipments', $this->expander->toBooleanQueryString('shipments'));
    }

    public function test_to_boolean_query_string_includes_the_stemmed_alternate(): void
    {
        $query = $this->expander->toBooleanQueryString('shipments');

        $this->assertContains('shipment', explode(' ', $query));
    }

    public function test_to_boolean_query_string_falls_back_to_raw_query_with_no_word_tokens(): void
    {
        $this->assertSame('!!!', $this->expander->toBooleanQueryString('!!!'));
    }

    public function test_to_boolean_query_string_never_contains_a_boolean_operator_regardless_of_input(): void
    {
        $query = $this->expander->toBooleanQueryString('C++ "quoted" -exclude (grouped) *wild* ~fuzzy~ <b>');

        foreach (['+', '-', '"', '(', ')', '*', '~', '<', '>', '@'] as $operatorChar) {
            $this->assertStringNotContainsString($operatorChar, $query);
        }
    }
}
