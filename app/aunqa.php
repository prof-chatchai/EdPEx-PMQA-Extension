<?php
declare(strict_types=1);

/**
 * AUN-QA helpers — program assessments + the zero-token bridge to KPI 7-4-074
 * ("Percentage of programmes assessed against the AUN-QA criteria with a score of 3 or higher").
 * Pure SQL aggregation; no LLM. Loaded on demand by views/aunqa.php.
 */

const AUNQA_KPI_CODE = '7-4-074';
const AUNQA_PASS_SCORE = 3.0;

/**
 * Summary per year: programs assessed, passed (>=3), percent.
 * @return array<int,array{year:int,assessed:int,passed:int,pct:float}>
 */
function aunqaYearSummary(): array
{
    $out = [];
    foreach (AppDB::all(
        "SELECT academic_year y, COUNT(*) n, SUM(CASE WHEN overall_score >= ? THEN 1 ELSE 0 END) p
           FROM aunqa_assessments GROUP BY academic_year ORDER BY academic_year",
        [AUNQA_PASS_SCORE]
    ) as $r) {
        $n = iv($r['n']);
        $p = iv($r['p']);
        $out[] = ['year' => iv($r['y']), 'assessed' => $n, 'passed' => $p,
                  'pct' => $n > 0 ? round($p / $n * 100, 2) : 0.0];
    }
    return $out;
}

/**
 * Push the latest-year pass-rate into KPI 7-4-074 (kpi_values.actual, data_status='entered').
 * Returns a Thai status line. Zero-token: SQL only; assessor still verifies on the kpiq page.
 */
function aunqaPushKpi(): string
{
    $sum = aunqaYearSummary();
    if ($sum === []) {
        return 'No AUN-QA assessment data yet';
    }
    $latest = end($sum);
    $defId = sv(AppDB::scalar("SELECT id FROM kpi_definitions WHERE code = ?", [AUNQA_KPI_CODE]));
    if ($defId === '') {
        return 'KPI not found: ' . AUNQA_KPI_CODE . ' in kpi_definitions';
    }
    $vid = sv(AppDB::scalar("SELECT id FROM kpi_values WHERE kpi_definition_id = ? AND academic_year = ?", [$defId, $latest['year']]));
    if ($vid !== '') {
        AppDB::exec("UPDATE kpi_values SET actual_value = ?, data_status = 'entered', updated_at = NOW() WHERE id = ?", [$latest['pct'], $vid]);
    } else {
        AppDB::exec(
            "INSERT INTO kpi_values (id, tenant_id, kpi_definition_id, academic_year, actual_value, data_status)
             VALUES (?,?,?,?,?, 'entered')",
            [appUuid(), appTenant(), $defId, $latest['year'], $latest['pct']]
        );
    }
    appAudit('aunqa_push_kpi', 'kpi_definition', $defId, ['year' => $latest['year'], 'pct' => $latest['pct']]);
    return sprintf('Updated %s year %d = %s%% (%d/%d programmes passed >=%.0f)',
        AUNQA_KPI_CODE, $latest['year'], (string) $latest['pct'], $latest['passed'], $latest['assessed'], AUNQA_PASS_SCORE);
}
