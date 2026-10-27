<?php

declare(strict_types=1);

namespace Tests\Scoring;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for app/scoring.php — the ADLI/LeTCI rubric engine (weakest-dimension cap +
 * management-by-fact gate). Exercises the real functions from app/scoring.php directly
 * (no mocks/fakes); bootstrap.php already loads config/config.php which wires the real
 * AppDB connection, matching the convention used by tests/Admission/*.
 */
final class ScoringTest extends TestCase
{
    /** Normal case: category 1-6 = ADLI, category 7 = LeTCI (also exercises the 6/7 boundary). */
    public function testScoringSchemeNormalCaseAndBoundary(): void
    {
        self::assertSame('ADLI', scoringScheme(1));
        self::assertSame('ADLI', scoringScheme(6)); // boundary: last ADLI category
        self::assertSame('LeTCI', scoringScheme(7)); // boundary: first LeTCI category
    }

    /**
     * Core rule under test: the item band is capped by the WEAKEST dimension, not an average.
     * Three dimensions at ordinal 5 and one at ordinal 1 must cap the item at 1, even though
     * the average of [5,5,5,1] is 4 — proving this is a min(), not a mean().
     */
    public function testScoringDimCapOkCapsAtWeakestDimensionNotAverage(): void
    {
        $dims = [5, 5, 5, 1];

        self::assertTrue(
            scoringDimCapOk(1, $dims),
            'Item ordinal equal to the weakest dimension must be allowed.'
        );
        self::assertFalse(
            scoringDimCapOk(2, $dims),
            'Item ordinal above the weakest dimension (1) must be rejected, even though the average of the four dimensions is 4.'
        );
        self::assertFalse(
            scoringDimCapOk(4, $dims),
            'Item ordinal at the average of the dimensions must still be rejected by the weakest-dimension cap.'
        );
    }

    /** Boundary case: item ordinal exactly equal to the weakest dimension is allowed (<=, not <). */
    public function testScoringDimCapOkBoundaryEqualToWeakestDimensionIsAllowed(): void
    {
        self::assertTrue(scoringDimCapOk(3, [3, 4, 5, 3]));
        self::assertFalse(scoringDimCapOk(4, [3, 4, 5, 3])); // one above the boundary must fail
    }

    /** Edge case: no dimensions supplied — nothing to cap against, so the item is rejected. */
    public function testScoringDimCapOkRejectsEmptyDimensionList(): void
    {
        self::assertFalse(scoringDimCapOk(0, []));
    }

    /**
     * Management-by-fact gate (G-13): a non-zero score submission must be rejected when the
     * item has zero linked evidence rows. Uses a fresh, never-seen assessment/item id pair so
     * the real scoringEvidenceCount() query genuinely returns 0 (read-only against the live DB,
     * matching the tests/Admission convention — no rows are inserted).
     */
    public function testScoringValidateRejectsNonZeroScoreWithZeroLinkedEvidence(): void
    {
        $assessId = 'test-assess-' . bin2hex(random_bytes(8));
        $itemCode = 'test-item-' . bin2hex(random_bytes(8));

        $errors = scoringValidate($assessId, $itemCode, 1, [
            'band' => '10-25%',
            'percent' => 10,
            'strengths' => 'A systematic initial approach',
            'dims' => [1, 1, 1, 1],
        ]);

        self::assertNotEmpty($errors, 'A non-zero score with no linked evidence must be rejected.');
        $hasEvidenceGateError = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'evidence')) {
                $hasEvidenceGateError = true;
                break;
            }
        }
        self::assertTrue($hasEvidenceGateError, 'Expected the management-by-fact (G-13) evidence error among: ' . implode(' | ', $errors));
    }

    /** Normal case: a zero-percent submission needs no justification/evidence/dimensions and validates cleanly. */
    public function testScoringValidateAcceptsZeroPercentWithoutEvidenceOrDims(): void
    {
        $assessId = 'test-assess-' . bin2hex(random_bytes(8));
        $itemCode = 'test-item-' . bin2hex(random_bytes(8));

        $errors = scoringValidate($assessId, $itemCode, 1, [
            'band' => '0-5%',
            'percent' => 0,
        ]);

        self::assertSame([], $errors);
    }
}
