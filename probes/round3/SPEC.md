# Round 3: moving the variability boundary (pre-registered)

Written and committed **before** `app/scoring_v2.php` exists and before any observation of it.

## What rounds 1–2 found

The unmodified engine (`app/scoring.php`) carries a framework as configuration only if it agrees with
EdPEx on three things fixed in code (condition C1): category 7 is the only results category and no
category uses two rubrics; the six EdPEx bands apply; four factors are rated on six levels.

## The redesign (to be implemented after this commit)

`app/scoring_v2.php` changes exactly what C1 says is fixed, and nothing else:

1. **Scheme per category from configuration.** The active framework definition may map each
   category number to a scheme code (`scheme_of_category`). Without the map, the v1 rule applies
   (category 7 uses LeTCI, all others ADLI).
2. **Bands and score values per scheme from configuration.** Bands and the values allowed in each
   band are read from the framework definition's `scoring.<scheme>.bands`, using the same format as
   `config/edpex.json`. Values are integers and need not be percentages.
3. **Factor count and levels per scheme from configuration.** `scoring.<scheme>.factors` and
   `scoring.<scheme>.levels` replace the hard-coded "4 factors, levels 0–5".

Everything else is unchanged: the cycle lock, the evidence rule, the justification rule, and the
band rule as it stood at the studied commits (the band may not exceed the weakest factor). The cap
was later removed in production because EdPEx excludes it. It is kept here deliberately, so that
the redesign changes variability only.

The active framework definition is supplied by the caller through `scoringFrameworkDef()`, the same
way v1 receives `edpex()`. The default is EdPEx, so existing callers keep v1's behaviour.

## Pre-registered condition C2 (for the redesigned engine)

A profile is carried by configuration if and only if:

- **C2a.** Every category is evaluated with exactly one rubric. A category rated with both a
  results and a process rubric cannot be carried, because the engine still assigns one scheme per
  category.
- **C2b.** Each rubric's scale is a finite ordered list of bands, each with a finite set of integer
  values.
- **C2c.** Each rubric rates k ≥ 1 factors on L ≥ 2 levels, **and its number of bands does not exceed
  L**. This clause follows from the retained band rule: the top band has ordinal (bands − 1) and must
  not exceed the top factor level (L − 1). Otherwise no top score can be accepted.

The point total is not a clause, because no engine function reads it.

## Observations (as in rounds 1–2, applied to v2)

For each profile, the probe writes a framework definition that transcribes the profile: schemes per
category, bands, values, factors and levels. A category rated with two rubrics is transcribed as
results, which is the closest v2 can express, and O1 records the loss.

- **O1.** For every category, the engine assigns the scheme the profile evaluates it with.
- **O2.** The bands and values the engine offers equal the profile's scale.
- **O3.** The engine accepts the top score when all k factors are at the top level, and rejects it
  when one factor is at level 0.

## Profiles

All 14 profiles of rounds 1–2 (`probes/profiles.json`, `probes/profiles_external.json`) and two new
ones (`probes/round3/profiles_round3.json`):

- **PMQA40**, a real framework: PMQA 4.0, the Thai public-sector award's 2024 revision, as assessed
  in 2025. Seven categories, with category 7 as results. Each item is rated at one of three levels:
  Basic = 300, Advance = 400, Significance = 500. Answers are written to show Approach, Deployment
  and Result, but AD-R is not rated as separate factors.
  - Source: OPDC-based assessment manual, Department of Mental Health, fiscal year 2568, pp. 5, 8
    and 9. The OCR text of those pages is in pmqa40_ocr_pages.txt (Thai original; English translation in pmqa40_ocr_pages_EN.txt); the manual is at https://psdg.dmh.go.th/pmqa40/ (PDF, fiscal year 2568).
- **V8**, synthetic: EdPEx with six bands but factors rated on four levels. Under C2c it is predicted
  **not** to be carried.

## What would falsify C2

Any profile whose observed outcome differs from its C2 prediction.
