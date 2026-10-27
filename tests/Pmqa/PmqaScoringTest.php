<?php

declare(strict_types=1);

namespace Tests\Pmqa;

use PHPUnit\Framework\TestCase;

/**
 * Empirical proof for the manuscript's "config-only, not a code rewrite" claim on a SECOND
 * ADLI/LeTCI-shaped framework: PMQA-2562 (config/pmqa.json). Unlike AUN-QA (app/aunqa.php +
 * schema/008_aunqa.sql — a standalone module with its own flat 1-5 scoring shape and its own
 * tables), PMQA-2562's process (cat 1-6, ADLI) / results (cat 7, LeTCI) split is structurally
 * identical to EdPEx's, so this test drives app/scoring.php's REAL, UNMODIFIED functions
 * (scoringScheme, scoringDimCapOk, scoringValidate) directly against PMQA item codes/category
 * numbers taken from config/pmqa.json — no mocks, no new scoring code, no new scoring tables.
 * Same real-DB convention as tests/Scoring/ScoringTest.php (bootstrap.php wires the real AppDB).
 */
final class PmqaScoringTest extends TestCase
{
    private static function pmqaConfig(): array
    {
        static $c = null;
        if ($c === null) {
            $c = json_decode(
                file_get_contents(__DIR__ . '/../../config/pmqa.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }
        return $c;
    }

    /** config/pmqa.json parses and round-trips PMQA-2562's 7-category, 1000-point structure. */
    public function testPmqaConfigStructureMatchesPmqa2562(): void
    {
        $cfg = self::pmqaConfig();
        self::assertSame('PMQA', $cfg['_meta']['framework']);
        self::assertSame(1000, $cfg['_meta']['max_points']);
        self::assertCount(7, $cfg['categories']);

        $totalPoints = array_sum(array_column($cfg['categories'], 'points'));
        self::assertSame(1000, $totalPoints, 'Category points must sum to 1000 (PMQA-2562 total).');

        // category 7 is the only LeTCI/results category, matching EdPEx's exact category-7 boundary.
        $cat7 = null;
        foreach ($cfg['categories'] as $cat) {
            if ($cat['n'] === 7) {
                $cat7 = $cat;
            } else {
                self::assertSame('ADLI', $cat['scoring']);
            }
        }
        self::assertNotNull($cat7);
        self::assertSame('LeTCI', $cat7['scoring']);
        self::assertSame('results', $cat7['type']);
    }

    /**
     * The core reuse claim: scoringScheme() from app/scoring.php — UNMODIFIED, no PMQA-specific
     * branch added — returns the correct ADLI/LeTCI scheme for PMQA's category numbers purely
     * because PMQA-2562 shares EdPEx's category-numbering convention (1-6 process, 7 results).
     */
    public function testRealScoringSchemeFunctionAppliesUnmodifiedToPmqaCategories(): void
    {
        foreach (self::pmqaConfig()['categories'] as $cat) {
            $expected = $cat['n'] === 7 ? 'LeTCI' : 'ADLI';
            self::assertSame(
                $expected,
                scoringScheme((int) $cat['n']),
                "scoringScheme() disagreed with pmqa.json for category {$cat['n']}"
            );
            self::assertSame($expected, $cat['scoring']);
        }
    }

    /**
     * The weakest-dimension cap (scoringDimCapOk(), pure/no-DB) is framework-agnostic by
     * construction — it takes ordinals, not a framework flag — so it is exercised here directly
     * against a PMQA item's 4 ADLI dimensions with zero code changes, mirroring
     * ScoringTest::testScoringDimCapOkCapsAtWeakestDimensionNotAverage but under a PMQA item code.
     */
    public function testRealScoringDimCapOkAppliesUnmodifiedToPmqaItem(): void
    {
        // PMQA-1.1 (Organizational Leadership) — 4 ADLI dims, weakest = 2.
        $dims = [5, 4, 2, 5];

        self::assertTrue(
            scoringDimCapOk(2, $dims),
            'PMQA item ordinal equal to the weakest dimension (2) must be allowed.'
        );
        self::assertFalse(
            scoringDimCapOk(3, $dims),
            'PMQA item ordinal above the weakest dimension (2) must be rejected by the SAME unmodified engine rule used for EdPEx.'
        );
    }

    /**
     * Full round-trip through the real scoringValidate() against a PMQA item code (PMQA-1.1,
     * category 1) with zero linked evidence — same management-by-fact gate (G-13) EdPEx items
     * get, applied to a PMQA item purely because scoringEvidenceCount() keys off item_code, not
     * a framework flag. Proves scoringValidate() needs no PMQA-specific code path.
     */
    public function testRealScoringValidateRejectsPmqaItemWithZeroEvidence(): void
    {
        $cfg = self::pmqaConfig();
        $item = $cfg['categories'][0]['items'][0]; // PMQA-1.1
        self::assertSame('PMQA-1.1', $item['code']);

        $assessId = 'test-pmqa-assess-' . bin2hex(random_bytes(8));

        $errors = scoringValidate($assessId, $item['code'], 1, [
            'band' => '10-25%',
            'percent' => 10,
            'strengths' => 'A systematic initial approach aligned with the PMQA-2562 criteria',
            'dims' => [2, 3, 2, 3],
        ]);

        self::assertNotEmpty($errors, 'A non-zero PMQA score with no linked evidence must be rejected.');
        $hasEvidenceGateError = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'evidence')) {
                $hasEvidenceGateError = true;
                break;
            }
        }
        self::assertTrue(
            $hasEvidenceGateError,
            'Expected the management-by-fact (G-13) evidence error among: ' . implode(' | ', $errors)
        );
    }

    /** A zero-percent PMQA-item submission needs no justification/evidence/dims — same rule as EdPEx. */
    public function testRealScoringValidateAcceptsZeroPercentPmqaItemWithoutEvidenceOrDims(): void
    {
        $cfg = self::pmqaConfig();
        $item = $cfg['categories'][6]['items'][0]; // PMQA-7.1, category 7 = LeTCI
        self::assertSame('PMQA-7.1', $item['code']);

        $assessId = 'test-pmqa-assess-' . bin2hex(random_bytes(8));

        $errors = scoringValidate($assessId, $item['code'], 7, [
            'band' => '0-5%',
            'percent' => 0,
        ]);

        self::assertSame([], $errors, 'Zero-percent PMQA results-category (LeTCI) submission must validate cleanly, same as EdPEx.');
    }
}
