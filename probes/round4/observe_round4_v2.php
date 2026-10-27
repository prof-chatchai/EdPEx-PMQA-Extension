<?php
declare(strict_types=1);

/**
 * Round 4, step 2b: observe app/scoring_v2.php on the round-4 profiles (round3 probe, unchanged except deviations
 * D1 and D4 in DEVIATIONS.md: profile values are passed and compared without integer casting; a continuous scale
 * is transcribed as one band holding the integers of its range and can never equal the profile scale (O2).
 * Profiles predicted 'uncertain' are not observed.
 *
 * For each profile a framework definition is written that transcribes the profile (scheme per category, bands,
 * values, factors, levels). A category rated with both rubrics is transcribed as results, the closest the
 * engine can express; O1 records the loss. Database reads are stubbed exactly as in rounds 1-2 (no reference
 * tables, one evidence record, cycle open). O1-O3 are defined as in rounds 1-2.
 *
 * Usage: php probes/round4/observe_round4_v2.php  -> probes/round4/observations_round4_v2.json
 */

$root = dirname(__DIR__, 2);

final class AppDB
{
    public static function all(string $sql, array $params = []): array { return []; }
    public static function scalar(string $sql, array $params = []): int { return 1; }
}
function appCycleLocked(string $id): bool { return false; }
function sv(mixed $v): string { return is_scalar($v) ? (string) $v : ''; }
function iv(mixed $v): int { return is_numeric($v) ? (int) $v : 0; }
function av(mixed $v): array { return is_array($v) ? $v : []; }
function edpex(): array
{
    static $c = null;
    return $c ??= json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/config/edpex.json'), true);
}
$GLOBALS['FW'] = null;
function scoringFrameworkDef(): array { return $GLOBALS['FW']; }

require "$root/app/scoring_v2.php";

$edpexScale = json_decode((string) file_get_contents(dirname(__DIR__) . '/profiles.json'), true)['edpex_scale'];
$r4 = json_decode((string) file_get_contents(__DIR__ . '/profiles_round4.json'), true);
$predictions = [];
foreach (json_decode((string) file_get_contents(__DIR__ . '/predictions_round4.json'), true)['predictions'] as $p) {
    if ($p['engine'] === 'scoring_v2') {
        $predictions[$p['id']] = $p['predicted'];
    }
}
$profiles = array_values(array_filter($r4['profiles'], static fn(array $p): bool => !str_starts_with($predictions[$p['id']], 'uncertain')));

function profileScale(array $side, array $edpexScale): array
{
    if (is_array($side['scale']) && isset($side['scale']['continuous'])) {
        [$lo, $hi] = $side['scale']['continuous'];
        return [range((int) $lo, (int) $hi)];
    }
    return $side['scale'] === 'edpex' ? $edpexScale : $side['scale'];
}
function isContinuous(array $side): bool
{
    return is_array($side['scale']) && isset($side['scale']['continuous']);
}

/** Transcribe a profile into a framework definition for scoring_v2.php. */
function transcribe(array $p, array $edpexScale): array
{
    $def = ['scheme_of_category' => [], 'scoring' => []];
    foreach (['process', 'results'] as $side) {
        $bands = [];
        foreach (profileScale($p[$side], $edpexScale) as $i => $values) {
            $bands[] = ['range' => "$side-band-$i", 'choices' => $values];
        }
        $def['scoring'][$side] = ['bands' => $bands, 'factors' => $p[$side]['factors'], 'levels' => $p[$side]['levels']];
    }
    foreach ($p['categories'] as [$n, $type]) {
        $def['scheme_of_category'][(string) $n] = $type === 'process' ? 'process' : 'results';
    }
    return $def;
}

$rows = [];
foreach ($profiles as $p) {
    $GLOBALS['FW'] = transcribe($p, $edpexScale);
    $obs = ['O1_scheme' => true, 'O2_scale' => true, 'O3_factors' => true];
    $notes = [];
    foreach ($p['categories'] as [$n, $type]) {
        $sides = $type === 'results+process' ? ['results', 'process'] : [$type];
        $engineScheme = scoringScheme((int) $n);
        foreach ($sides as $side) {
            if (count($sides) > 1 || $engineScheme !== $side) {
                if ($obs['O1_scheme']) {
                    $notes[] = "category $n ($type): engine assigns one scheme ($engineScheme)";
                }
                $obs['O1_scheme'] = false;
            }
            $engineScale = array_map(static fn(string $b): array => scoringChoices($side, $b), scoringBands($side));
            if (isContinuous($p[$side]) || $engineScale != profileScale($p[$side], $edpexScale)) {
                if ($obs['O2_scale']) {
                    $notes[] = "$side scale differs" . (isContinuous($p[$side]) ? ' (continuous)' : '');
                }
                $obs['O2_scale'] = false;
            }
            $k = count($p[$side]['factors']);
            $top = $p[$side]['levels'] - 1;
            $bands = scoringBands($side);
            $topBand = end($bands);
            $topVal = max(scoringChoices($side, $topBand));
            $in = ['band' => $topBand, 'percent' => $topVal, 'strengths' => 'x', 'dims' => array_fill(0, $k, $top)];
            $acceptsTop = scoringValidate('probe', "$n.1", (int) $n, $in) === [];
            $in['dims'][0] = 0;
            $rejectsWeak = scoringValidate('probe', "$n.1", (int) $n, $in) !== [];
            if (!($acceptsTop && $rejectsWeak)) {
                if ($obs['O3_factors']) {
                    $notes[] = "$side factors ($k factors, {$p[$side]['levels']} levels, " . count($bands) . ' bands): top score '
                        . ($acceptsTop ? 'accepted' : 'rejected') . ', weak ' . ($rejectsWeak ? 'rejected' : 'accepted');
                }
                $obs['O3_factors'] = false;
            }
        }
    }
    $observed = !in_array(false, $obs, true) ? 'configuration only' : 'dedicated module';
    $rows[] = ['id' => $p['id'], 'kind' => $p['kind'], 'observations' => $obs, 'notes' => $notes,
               'predicted' => $predictions[$p['id']], 'observed' => $observed,
               'prediction_correct' => $predictions[$p['id']] === $observed];
}
$lf = static fn(string $f): string => hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($f)));
$out = ['engine_v2_sha256_lf' => $lf("$root/app/scoring_v2.php"), 'engine_v1_sha256_lf' => $lf("$root/app/scoring.php"),
        'predictions_sha256_lf' => $lf(__DIR__ . '/predictions_round4.json'), 'round' => 4,
        'profiles' => $rows,
        'correct' => count(array_filter($rows, static fn($r) => $r['prediction_correct'])), 'total' => count($rows)];
file_put_contents(__DIR__ . '/observations_round4_v2.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
foreach ($rows as $r) {
    printf("%-10s predicted %-20s observed %-20s %s  %s\n", $r['id'], $r['predicted'], $r['observed'],
           $r['prediction_correct'] ? 'OK  ' : 'MISS', implode('; ', $r['notes']));
}
printf("%d/%d predictions correct\n", $out['correct'], $out['total']);
