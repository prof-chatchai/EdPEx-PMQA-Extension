# PMQA Extension Artifact

PMQA here is the Thai Public Sector Management Quality Award criteria, 2019 (B.E. 2562) edition, adapted by the Office of the Public Sector Development Commission from the Malcolm Baldrige National Quality Award.

Reproducibility artifact for the paper *"Framework-Agnostic Extensibility of a
Schema-as-Data Quality-Assessment Platform: An Empirical Extension Study from
EdPEx to PMQA"* (submitted to PeerJ Computer Science).

This is a **focused extract** of the relevant files from the authors' larger
institutional EdPEx information system (utkic), containing exactly the files
needed to verify this paper's claims — not the full production system (which
contains unrelated subsystems: admissions, alumni, MOOC, procurement, etc.,
not discussed in this paper).

## What this demonstrates

`app/scoring.php` is the shared ADLI/LeTCI scoring engine, used unmodified by
both EdPEx (`config/edpex.json`) and the PMQA extension
(`config/pmqa.json`) added for this paper. `app/aunqa.php` +
`schema/008_aunqa.sql` is the contrasting AUN-QA case, which required a
dedicated module rather than configuration alone.

## Files

```
app/scoring.php          — the ADLI/LeTCI scoring engine (0 lines changed to add PMQA)
app/aunqa.php             — the AUN-QA integration module (contrasting, module-based case)
config/edpex.json         — EdPEx rubric configuration
config/pmqa.json          — PMQA rubric configuration (new, config-only)
schema/008_aunqa.sql       — AUN-QA schema migration (new tables required)
schema/156_pmqa.sql        — PMQA schema migration (framework_code registration only)
tests/bootstrap.php        — PHPUnit bootstrap (requires ../config/config.php, not included — see below)
tests/Scoring/ScoringTest.php  — 6 tests for the EdPEx scoring path
tests/Pmqa/PmqaScoringTest.php — 5 tests for the PMQA scoring path
```

## Running the tests

The scoring-*validation* tests are pure-function tests and run without a
database. The management-by-fact-gate tests in `PmqaScoringTest.php` and
`ScoringTest.php` query a live MySQL database through the application's
config loader (`config/config.php`, not included here since it reads
connection details from environment variables per this project's convention,
not hardcoded credentials).

To run the full suite yourself:
1. Provide your own `config/config.php` (or the relevant environment
   variables — `APP_DB_HOST`, `APP_DB_NAME`, `APP_DB_USER`, `APP_DB_PASS`,
   see `config/config.example.php`) pointing at a MySQL instance with the
   `edpex`/`prompti2_arc`-shaped schema (apply `schema/*.sql` in order).
2. `composer require phpunit/phpunit` (or use a project-wide `composer.json`
   if you have the full utkic repository).
3. `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Scoring tests/Pmqa`

Expected result at the time of writing: 11 tests, 46 assertions, all passing.

## Provenance

Extracted from the authors' private institutional repository at commit
`ab9c30e` (PMQA addition) / `6f79b81` (base test/CI infrastructure).
The full repository is not yet public; this artifact is provided specifically
to support review and reproducibility of this paper's claims.

## Prospective test of the structural condition (`probes/`, v1.3)

No database or institutional data are used.

1. `probes/profiles.json` - ten structural profiles: EdPEx and PMQA (controls), the 2025 Baldrige Award
   Criteria (transcribed from the official NIST document; URL and SHA-256 recorded in the file) and seven
   synthetic variants of EdPEx, each changing one structural property.
2. `python probes/predict.py` - applies the condition of the article's extension protocol clause by clause
   and writes `probes/predictions.json`. These predictions were committed and pushed (commit `470213e`)
   before any observation.
3. `php probes/observe.php` - runs the unmodified `app/scoring.php` (database reads replaced by fixed
   in-memory values) against each profile and writes `probes/observations.json` (commit `02cbe4b`).
   Result: 7 of 10 predictions correct.
4. `python probes/refine.py` - post hoc check of the revised condition described in the article
   (`probes/refined.json`, 10 of 10 consistent; a consistency check, not a test).

## License

Provided for academic review and reproducibility purposes. Contact the
corresponding author (chatchai.t@mail.rmutk.ac.th) for reuse terms.

## Round 3: moving the variability boundary (tag v1.5.0)

`probes/round3/SPEC.md` specifies a redesign that makes configurable exactly what the revised condition
C1 found fixed in code (scheme per category, bands and values per scheme, factor count and levels), keeps
every other rule (including the band rule as it stood at the studied commits), and states condition C2
for the redesigned engine. Spec, profiles and predictions were pushed as commit `d4bd327` before
`app/scoring_v2.php` was written.

    python probes/round3/predict_round3.py      # predictions_round3.json (already committed)
    php probes/round3/observe_round3.php        # observations_round3.json

`app/scoring_v2.php` differs from `app/scoring.php` by 29 added and 6 removed lines
(`git diff --no-index --numstat app/scoring.php app/scoring_v2.php`). With no framework definition supplied
it behaves as v1.

## Language note

All code comments, messages, configuration labels and documentation in this repository are in English.
The criteria titles in `config/edpex.json` and `config/pmqa.json` are English translations of the official Thai
criteria (EdPEx 2024-2027, after the Baldrige Education framework; PMQA 2019). The only Thai-language file is
`probes/round3/pmqa40_ocr_pages.txt`, the verbatim OCR of the cited Thai government manual kept as the
primary-source record; its English translation is `probes/round3/pmqa40_ocr_pages_EN.txt`.
