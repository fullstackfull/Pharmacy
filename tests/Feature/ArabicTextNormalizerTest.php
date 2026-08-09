<?php

namespace Tests\Feature;

use App\Services\Search\ArabicTextNormalizer;
use Tests\TestCase;

/**
 * The cases here are drawn from the store's real catalogue, not invented. The headline one is the
 * defect that motivated the whole change: the product "جوفيلاب حمايه من الشمس" was unreachable by
 * anyone typing "حماية", the ordinary spelling of the word.
 */
class ArabicTextNormalizerTest extends TestCase
{
    private ArabicTextNormalizer $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new ArabicTextNormalizer();
    }

    /** The measured production failure: 2 hits for one spelling, 0 for the other. */
    public function test_taa_marbuta_and_haa_are_the_same_word(): void
    {
        $stored = $this->n->normalize('جوفيلاب حمايه من الشمس');
        $typed  = $this->n->normalize('حماية');

        $this->assertStringContainsString($typed, $stored);
    }

    public function test_alef_forms_fold_together(): void
    {
        $bare = $this->n->normalize('احمد');

        foreach (['أحمد', 'إحمد', 'آحمد', 'ٱحمد'] as $variant) {
            $this->assertSame($bare, $this->n->normalize($variant), $variant);
        }
    }

    public function test_alef_maqsura_folds_to_yaa(): void
    {
        $this->assertSame($this->n->normalize('علي'), $this->n->normalize('على'));
    }

    public function test_diacritics_are_ignored(): void
    {
        $this->assertSame($this->n->normalize('كتاب'), $this->n->normalize('كِتَابٌ'));
    }

    public function test_tatweel_is_ignored(): void
    {
        $this->assertSame($this->n->normalize('سيروم'), $this->n->normalize('ســيــروم'));
    }

    /** "٣٠ مل" and "30 مل" are the same size to a customer. */
    public function test_arabic_indic_digits_fold_to_latin(): void
    {
        $this->assertSame('30 مل', $this->n->normalize('٣٠ مل'));
        $this->assertSame('30 مل', $this->n->normalize('۳۰ مل'));
    }

    public function test_persian_kaf_folds_to_arabic_kaf(): void
    {
        $this->assertSame($this->n->normalize('كريم'), $this->n->normalize('کریم'));
    }

    // ---- Latin, because this catalogue is mixed ----

    public function test_latin_is_lowercased_and_punctuation_collapsed(): void
    {
        $this->assertSame('medee serum vitamin c', $this->n->normalize('MEDEE Serum - Vitamin C!'));
    }

    public function test_hyphenated_terms_match_spaced_terms(): void
    {
        $this->assertSame($this->n->normalize('vitamin c'), $this->n->normalize('vitamin-c'));
    }

    public function test_whitespace_is_collapsed_and_trimmed(): void
    {
        $this->assertSame('medee hydra 50 ml', $this->n->normalize("  MEDEE   hydra\t50  ml \n"));
    }

    // ---- edges a caller will hit ----

    public function test_empty_and_null_normalize_to_an_empty_string(): void
    {
        $this->assertSame('', $this->n->normalize(null));
        $this->assertSame('', $this->n->normalize(''));
        $this->assertSame('', $this->n->normalize('   '));
        $this->assertSame('', $this->n->normalize('!!!'));   // punctuation only
    }

    public function test_terms_splits_deduplicates_and_drops_blanks(): void
    {
        $this->assertSame(['vitamin', 'c'], $this->n->terms('  Vitamin   c  vitamin '));
        $this->assertSame([], $this->n->terms(null));
        $this->assertSame([], $this->n->terms('---'));
    }

    /**
     * Folding must not merge words a reader considers different — that would silently return wrong
     * products, which is worse than returning none.
     */
    public function test_distinct_words_stay_distinct(): void
    {
        $this->assertNotSame($this->n->normalize('سيروم'), $this->n->normalize('كريم'));
        $this->assertNotSame($this->n->normalize('retinol'), $this->n->normalize('retinal'));
    }

    public function test_detects_arabic_script(): void
    {
        $this->assertTrue($this->n->containsArabic('حماية'));
        $this->assertTrue($this->n->containsArabic('MEDEE ميدي'));
        $this->assertFalse($this->n->containsArabic('MEDEE 30 ml'));
        $this->assertFalse($this->n->containsArabic(null));
    }
}
