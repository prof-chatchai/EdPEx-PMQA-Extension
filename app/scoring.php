<?php
declare(strict_types=1);

/**
 * EdPEx — scoring engine (Phase B). The single home of ADLI/LeTCI scoring rules,
 * shared by the PHP web (views/assess.php) and, later, the API POST endpoint + clients.
 *
 * Rules (plan.md §4 / config/edpex.json scoring):
 *  - Categories 1–6 = ADLI, Category 7 = LeTCI; each has 6 bands with a discrete % choice set.
 *  - Assessor picks a band, then a % within that band (no free numbers).
 *  - Mandatory justification (strengths or OFI) once a score > 0 is given.
 *  - Management-by-fact gate (G-13): a score > 0 needs ≥ 1 evidence row for the item.
 *  - Roll-up uses edpexOverall(): item = % × points → category Σ → overall Σ (max 1000).
 */

/** ADLI for categories 1–6, LeTCI for category 7. */
function scoringScheme(int $categoryN): string
{
    return $categoryN === 7 ? 'LeTCI' : 'ADLI';
}

/**
 * Band ranges for a scheme, in order. DB-first: ref_scoring_bands is the runtime SSOT (seeded from
 * edpex.json by tools/seed_rubric.php). Falls back to the JSON when the ref tables are unseeded
 * (AppDB tolerates a missing table → empty result). @return list<string>
 */
function scoringBands(string $scheme): array
{
    $out = [];
    foreach (AppDB::all(
        "SELECT b.range_label FROM ref_scoring_bands b
           JOIN ref_scoring_schemes s ON b.scheme_id = s.id
          WHERE s.code = ? ORDER BY b.ordinal",
        [$scheme]
    ) as $r) {
        $out[] = sv($r['range_label']);
    }
    if ($out !== []) {
        return $out;
    }
    foreach (av(edpex()['scoring'][$scheme]['bands'] ?? null) as $b) {   // fallback: edpex.json
        $out[] = sv(av($b)['range'] ?? '');
    }
    return array_values(array_filter($out, static fn(string $s): bool => $s !== ''));
}

/** Allowed discrete percents for a band range. DB-first (ref_band_choices), JSON fallback. @return list<int> */
function scoringChoices(string $scheme, string $bandRange): array
{
    $rows = AppDB::all(
        "SELECT c.percent FROM ref_band_choices c
           JOIN ref_scoring_bands b ON c.band_id = b.id
           JOIN ref_scoring_schemes s ON b.scheme_id = s.id
          WHERE s.code = ? AND b.range_label = ? ORDER BY c.percent",
        [$scheme, $bandRange]
    );
    if ($rows !== []) {
        return array_values(array_map(static fn(array $r): int => iv($r['percent']), $rows));
    }
    foreach (av(edpex()['scoring'][$scheme]['bands'] ?? null) as $b) {   // fallback: edpex.json
        $b = av($b);
        if (sv($b['range'] ?? '') === $bandRange) {
            return array_values(array_map('intval', av($b['choices'] ?? null)));
        }
    }
    return [];
}

/** Ordinal index (0-based) of a band range within its scheme's band order, or -1. Pure. */
function scoringBandOrdinal(string $scheme, string $bandRange): int
{
    $i = array_search($bandRange, scoringBands($scheme), true);
    return $i === false ? -1 : (int) $i;
}

/**
 * EdPEx weakest-dimension rule (ADLI): the item band may not exceed the lowest dimension band.
 * Pure (no DB) so it is unit-testable. @param list<int> $dimOrds
 */
function scoringDimCapOk(int $itemOrd, array $dimOrds): bool
{
    $dimOrds = array_map('intval', array_values($dimOrds));
    return $dimOrds !== [] && $itemOrd <= min($dimOrds);
}

/** Evidence rows linked to an item (G-13 management-by-fact gate). */
function scoringEvidenceCount(string $assessId, string $itemCode): int
{
    return iv(AppDB::scalar(
        "SELECT COUNT(*) FROM {evidence} WHERE item_code = ? AND (assessment_id = ? OR assessment_id IS NULL)",
        [$itemCode, $assessId]
    ));
}

/**
 * Validate one score submission against the rules. Returns a list of Thai error messages
 * (empty list = valid).
 * @param array{band?:string,percent?:int|string,strengths?:string,ofi?:string} $in
 * @return list<string>
 */
function scoringValidate(string $assessId, string $itemCode, int $categoryN, array $in): array
{
    $errors = [];
    // cycle lock (step ④): a final/approved cycle rejects every score write (web + API alike)
    if (function_exists('appCycleLocked') && appCycleLocked($assessId)) {
        $errors[] = 'This assessment cycle is approved/locked; scores cannot be edited (an administrator must reopen it)';
        return $errors;
    }
    $scheme = scoringScheme($categoryN);
    $band   = trim(sv($in['band'] ?? ''));
    $pct    = (int) ($in['percent'] ?? -1);
    $hasJust = trim(sv($in['strengths'] ?? '')) !== '' || trim(sv($in['ofi'] ?? '')) !== '';

    if (!in_array($band, scoringBands($scheme), true)) {
        $errors[] = "Please select a valid ({$scheme}) band";
    } else {
        $choices = scoringChoices($scheme, $band);
        if (!in_array($pct, $choices, true)) {
            $errors[] = 'Percentage must be one of the values in the selected band: ' . implode(' / ', array_map('strval', $choices)) . '%';
        }
    }
    if ($pct > 0 && !$hasJust) {
        $errors[] = 'Strengths or opportunities for improvement (score rationale) are required';
    }
    if ($pct > 0 && scoringEvidenceCount($assessId, $itemCode) === 0) {
        $errors[] = 'At least 1 piece of evidence is required before scoring (management by fact, G-13)';
    }
    // ADLI (Categories 1–6) / LeTCI (Category 7): require the 4 dimensions and enforce the weakest-dimension
    // cap — same rule for both schemes (Spec 003 + 004); dims map to scores.dim1-4.
    if ($pct > 0) {
        $ords = [];
        foreach (av($in['dims'] ?? null) as $d) {
            if ($d !== '' && $d !== null && is_numeric($d)) {
                $ords[] = (int) $d;
            }
        }
        if (count($ords) !== 4 || min($ords) < 0 || max($ords) > 5) {
            $errors[] = "All 4 dimensions ({$scheme}) must be rated at levels 0–5";
        } elseif (($itemOrd = scoringBandOrdinal($scheme, $band)) >= 0 && !scoringDimCapOk($itemOrd, $ords)) {
            $errors[] = 'Overall band must not exceed the weakest dimension (level ' . min($ords) . '): the weakest dimension caps the score';
        }
    }
    return $errors;
}

/**
 * Validate then upsert one item score (+ audit). Returns ['ok'=>bool, 'errors'=>list<string>].
 * @param array{band?:string,percent?:int|string,strengths?:string,ofi?:string,dims?:array<int,int|string>} $in
 * @return array{ok:bool,errors:list<string>}
 */
function scoringSave(string $assessId, string $itemCode, int $categoryN, array $in, string $userId): array
{
    $errors = scoringValidate($assessId, $itemCode, $categoryN, $in);
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }
    $band = trim(sv($in['band'] ?? ''));
    $pct  = (int) ($in['percent'] ?? 0);
    $strengths = trim(sv($in['strengths'] ?? ''));
    $ofi       = trim(sv($in['ofi'] ?? ''));
    $dims = av($in['dims'] ?? null);
    $dim  = static fn(int $i): ?int => isset($dims[$i]) && $dims[$i] !== '' ? (int) $dims[$i] : null;

    $old = AppDB::one("SELECT score_pct FROM {scores} WHERE assessment_id = ? AND item_code = ?", [$assessId, $itemCode]);
    AppDB::exec(
        "INSERT INTO {scores}
            (id, tenant_id, assessment_id, item_code, score_pct, band, dim1, dim2, dim3, dim4, strengths, ofi, updated_by, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
            score_pct=VALUES(score_pct), band=VALUES(band),
            dim1=VALUES(dim1), dim2=VALUES(dim2), dim3=VALUES(dim3), dim4=VALUES(dim4),
            strengths=VALUES(strengths), ofi=VALUES(ofi), updated_by=VALUES(updated_by), updated_at=NOW()",
        [appUuid(), appTenant(), $assessId, $itemCode, $pct, $band,
         $dim(0), $dim(1), $dim(2), $dim(3), $strengths !== '' ? $strengths : null, $ofi !== '' ? $ofi : null, $userId]
    );
    // S5 (scoring normalization): mirror the 4 ordinal dims into the normalized
    // score_dimensions store in parallel. dim1..dim4 stay the source of truth until a
    // later wave drops them; this is best-effort and a no-op until ref_scoring_dimensions
    // is seeded (count !== 4), so it never breaks a save.
    if ($dim(0) !== null && $dim(1) !== null && $dim(2) !== null && $dim(3) !== null) {
        $scoreId = sv(AppDB::scalar("SELECT id FROM {scores} WHERE assessment_id = ? AND item_code = ?", [$assessId, $itemCode]));
        $scheme  = scoringScheme($categoryN);
        $dimIds  = [];   // ordinal 1..4 → ref_scoring_dimensions.id (scheme-specific)
        foreach (AppDB::all(
            "SELECT d.id, d.ordinal FROM ref_scoring_dimensions d
               JOIN ref_scoring_schemes s ON d.scheme_id = s.id
              WHERE s.code = ? ORDER BY d.ordinal",
            [$scheme]
        ) as $r) {
            $dimIds[iv($r['ordinal'])] = sv($r['id']);
        }
        if ($scoreId !== '' && count($dimIds) === 4) {
            AppDB::exec("DELETE FROM score_dimensions WHERE score_id = ?", [$scoreId]);
            for ($i = 0; $i < 4; $i++) {
                AppDB::exec(
                    "INSERT INTO score_dimensions (id, score_id, dimension_id, level) VALUES (?,?,?,?)",
                    [appUuid(), $scoreId, $dimIds[$i + 1], (int) $dim($i)]
                );
            }
        }
    }
    // S11 F005: mirror the narrative (strengths + ofi) into item_score_narrative (source='self'),
    // keeping scores.strengths/ofi as the source columns. No-op-safe — skipped if the table isn't
    // migrated yet (matches the score_dimensions parallel-write pattern; a save never fatals).
    static $hasNarrative = null;
    if ($hasNarrative === null) {
        $hasNarrative = (int) AppDB::scalar(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'item_score_narrative'"
        ) > 0;
    }
    if ($hasNarrative) {
        AppDB::exec(
            "INSERT INTO item_score_narrative (id, tenant_id, assessment_id, item_code, source, strengths, ofi_text)
             VALUES (?,?,?,?, 'self', ?, ?)
             ON DUPLICATE KEY UPDATE strengths = VALUES(strengths), ofi_text = VALUES(ofi_text)",
            [appUuid(), appTenant(), $assessId, $itemCode, $strengths !== '' ? $strengths : null, $ofi !== '' ? $ofi : null]
        );
    }
    appAudit($old ? 'score.update' : 'score.create', 'score', $itemCode, ['percent' => $pct, 'band' => $band]);
    return ['ok' => true, 'errors' => []];
}
