# Round 4: a second, third-party engine and new profiles (pre-registered)

Written and committed **before** any round-4 observation on either engine and before any round-4
observation script exists. This commit contains the specification, the profiles, the prediction script and
its output only.

## Why this round

Rounds 1–3 left three weaknesses: one platform, built by the authors; ten to sixteen profiles per round; and
exact McNemar tests against rules that ignore the engine that could not reach significance (p = 1.0 and 0.5).
Round 4 adds an engine the authors did not write and new profiles, and fixes the analysis in advance.

## Engines

1. **Open edX ORA2** (Open Response Assessment), third-party, open source.
   - URL: https://github.com/openedx/edx-ora2
   - Commit: `96b4ad10b1f1d6108b4440fe0f3b7df0bdb11c34` (committed 2026-10-23), licence AGPL-3.0-only.
   - Not vendored here. Cloned to a sibling directory (`round4_platform/edx-ora2`); dependencies installed
     from the project's own lockfile with `uv sync --frozen --python 3.12` (Python 3.12, Django per
     `uv.lock`). The observation harness boots ORA2's `settings.test` with an in-memory SQLite database,
     removing only the two XBlock workbench UI apps (`workbench`, `sample_xblocks.basic`), which the
     frozen install does not provide and which take no part in rubric validation or scoring.
   - Smoke test done before this commit, with ORA2's own sample rubric only
     (`src/openassessment/assessment/test/data/rubric/project_plan_rubric.json`), no study profile:
     `validate_rubric` → `(True, '')`, `points_possible` 6, all-top `points_earned` 6, `to_float` 1.0.
   - Rubric content is pure configuration: criteria, options, option points.
2. **`app/scoring_v2.php`** at tag v1.5.2, unchanged in behaviour since round 3; condition C2 as published
   in `probes/round3/SPEC.md`.

## Condition C_ORA (derived from ORA2 source only)

Paths are relative to `src/openassessment/` at the commit above.

| Fixed in code | Trace |
|---|---|
| Option points are integers ≥ 0 | `assessment/serializers/base.py:32` (`IntegerField(min_value=0)`); `assessment/models/base.py:216` (`PositiveIntegerField`); invalid serializer → `InvalidRubric` → `validate_rubric` returns False (`xblock/utils/validation.py:197-200`, `serializers/base.py:242-294`) |
| Each criterion offers a finite list of options; an assessment selects exactly one option per criterion, by name or by exact point value | `models/base.py:196-233`, `324-382` (`find_option`, `find_option_for_points` raise `InvalidRubricSelection` for anything not in the list), `725-727`, `734-806` |
| Rubric score is the unweighted sum of the selected options' points; the maximum is the sum over criteria of each criterion's largest option; the fraction is their ratio | `models/base.py:86-89`, `189-193`, `447-465`; `serializers/base.py:229-234` |
| Every criterion with options must be assessed; the maximum does not change with the selection | `models/base.py:868-917` (`_check_has_all_criteria`, `_check_all_criteria_assessed`); `86-89` |
| At least one criterion; unique option names per criterion; at least one prompt | `serializers/base.py:56-59`; `xblock/utils/validation.py:202-212` (always satisfied by the transcription, never a clause) |

Weights are not a field, but a non-negative rational weight can be folded into option points by one common
integer multiplier K, which leaves `to_float` unchanged. Hence, a framework is carried by configuration iff:

- **A1.** Every rating value is a non-negative rational number.
- **A2.** Each rated element takes one of a finite set of values.
- **A3.** The framework's overall score, if it defines one, is a non-negative linear combination of the
  element ratings with no intercept (a weighted sum; a mean within an element counts), or no overall score is
  defined.
- **A4.** Every element is rated; no element may be declared not applicable and leave the denominator.

## Condition C2 (unchanged)

As in `probes/round3/SPEC.md`: C2a one rubric per category; C2b finite ordered bands of integer values;
C2c for every rubric, number of bands ≤ factor levels. C2 says nothing about aggregation or "not
applicable", because no function of `scoring_v2.php` reads them.

## Profiles

Sources, assumptions and exclusions are in `profiles_round4.json`.

- **ORA2:** all 16 profiles of rounds 1–3 (none ever observed on ORA2) plus the 10 round-4 profiles.
- **scoring_v2.php:** the 10 round-4 profiles only. The 16 earlier profiles were observed on it in round 3
  and are not prospective.

New real-framework profiles:
- **CAF2020FT**: CAF 2020 fine-tuned scoring (EIPA, pp. 53–56).
- **BaldrigeHC2023**: Baldrige Health Care 2023–2024 (NIST free sample). Category structure verified;
  scale, levels and aggregation are on pages not in the sample, so marked uncertain.

Considered and **excluded because no official source with the needed structure was accessible**: ABEF,
Singapore BE, Japan Quality Award and ISO 9004:2018. The reasons are in `excluded_unverifiable`. EdPEx,
PMQA 2019, PMQA 4.0, Baldrige 2025, EFQM 2025, CAF 2020 and AUN-QA enter as the prior profiles. For
ORA2, A3 needs each one's aggregation rule:
- verified for EdPEx and PMQA (`app/scoring.php:13`; EdPEx points table p. 21);
- for CAF 2020, the document defines no total (pp. 53–56);
- marked uncertain for Baldrige 2025, TQA, EFQM 2025, AUN-QA and PMQA 4.0.

**Uncertainty rule.** A clause that depends on an uncertain property is null. A profile with any null clause
is predicted "uncertain" and excluded from the primary analysis of that engine. It is still observed and
reported descriptively. Nothing is guessed.

### Variant rule (fixed before predicting)

One synthetic variant per clause of each condition, each a minimal change to EdPEx that violates that clause
and, where possible, no other clause of either condition, plus one control that violates none.

| Variant | Violates |
|---|---|
| W1 | A1, a band below zero |
| W2 | A2, a continuous 0–100 scale; this unavoidably also violates C2b |
| W3 | A3, overall = weakest category |
| W4 | A4, a category may be not applicable |
| W5 | C2a, one category rated with both rubrics |
| W6 | C2c, seven bands with six levels |
| W7 | none, control: nine categories, 500 points |
| W8 | C2b, values in steps of 2.5 |

No variant was added, dropped or changed after the predictions were computed.

## Observations (phase 2, after this commit is pushed)

**ORA2.** Each profile is transcribed into a `rubric_dict`:
- one prompt;
- one criterion per (category, rubric) pair and, where factors are scored separately (CAF2020FT,
  Award2025), per factor;
- options = the distinct allowed values, with points = K · w · v, where w is the category's points (1 when
  the profile gives none) and K is the smallest positive integer making every product an integer;
- a continuous scale is transcribed as the integers in its range, the closest finite form;
- negative products are written as they are.

Then:
- **O1'.** `validate_rubric(rubric_dict, None, False, _)` returns True and `rubric_from_dict` succeeds.
- **O2'.** For each criterion, the stored option points equal K · w · (profile values).
- **O3'.** Three selections are scored with `Assessment.create`, `AssessmentPart.create_from_option_points`
  and `Assessment.to_float`, and `to_float` must equal the official normalised score within 1e-9:
  - all-top;
  - all-bottom;
  - mixed: criteria in order, even index at the bottom value, odd index at the top value, with these
    replacements, fixed now:
    - continuous scale: criterion 0 takes 37.5;
    - `na_allowed`: criterion 0 is not applicable and is passed with no selection;
    - `min`: the official score is the smallest category percentage;
    - both rubrics in one category: category % = mean of the two.

  An exception counts as failure.

Carried ⇔ O1' ∧ O2' ∧ O3'. Uncertain profiles are observed on O1' and O2' only.

**scoring_v2.php.** Same probe, stubs and O1–O3 as `probes/round3/observe_round3.php`, applied to the
round-4 profiles. Uncertain profiles are not observed.

## Analysis plan (fixed now)

The **primary set** is the profiles with a non-uncertain prediction:
- ORA2: 20 profiles (26 minus Award2025, TQA, EFQM2025, AUN-QA, PMQA40 and BaldrigeHC2023);
- scoring_v2.php: 9 profiles.

For each engine:
1. Accuracy, with a Clopper–Pearson exact 95% CI.
2. The same baselines as rounds 1–3:
   - majority outcome of the round for that engine (from the observations, after the fact);
   - all-configuration;
   - Baldrige-derived ⇒ configuration, otherwise module (`baldrige_derived`; EdPEx variants count as
     Baldrige-derived, as before).
3. Exact two-sided McNemar (binomial on discordant profiles) of the condition against each baseline.

**Pooled across rounds 1–4:** every (condition, profile, engine) prediction made before observation, that
is, round 1 (C0), round 2 (C1), round 3 (C2) and round 4 (C_ORA, C2).
- Exact McNemar against all-configuration and against the family rule.
- Reported twice: authors' platform only, and both engines.
- Pooled units are not independent: the same profiles recur across engines and rounds. The pooled test is
  therefore descriptive and is reported with that caveat.

No other test is primary. α = 0.05, two-sided, no correction; three baselines × two engines are reported in
full.

## Power, stated honestly

The exact McNemar test cannot reach p < 0.05 with fewer than six discordant profiles, all in one direction
(p = 2^(1−k)).

- **ORA2:** the predictions put 4 primary profiles at "module" (W1–W4). Even if all are correct, the
  per-engine test against all-configuration gives at best p = 0.125. Most real frameworks are predicted to
  be carried, so ORA2 is expected to show that its boundary is wide, not to beat the trivial rule
  statistically.
- **scoring_v2.php:** the predicted failures are W2, W5, W6 and W8, so the best case is again p = 0.125.
- **Pooled tests:** the only ones with a realistic chance. Rounds 1–3 alone already give b = 9, c = 1
  against all-configuration (p ≈ 0.021, computed from the committed observations; not reported in the
  article so far). If every round-4 prediction is correct, the pooled p is 0.0018 for the
  authors' platform and 0.0001 for both engines. A single wrong prediction among the predicted failures
  moves both figures. The output of `predict_round4.py` is in `predictions_round4.json`.
- **Bias in the variant rule:** most failures come from synthetic variants built from the clauses. They
  can show that the conditions locate each clause, not that the clauses matter often in real frameworks.

## What would falsify the conditions

Any primary profile whose observed outcome differs from its prediction.
