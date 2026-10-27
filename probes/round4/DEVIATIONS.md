# Round 4: deviations from the pre-registered SPEC (commit 7358a7e)

All deviations were recorded while implementing the observation scripts, before the results were read.
None changes a prediction, a profile or the primary analysis.

- **D1 (scoring_v2 probe, O2).** The round-3 probe cast profile values to integers before comparing them
  with the values the engine offers. For the round-3 profiles this made no difference, because all their
  values were integers. W8 has non-integer values, and with the cast its scale would compare equal to the
  engine's truncated scale (`app/scoring_v2.php:85` casts choices with `intval`). The probe now passes the
  profile values to the engine unchanged and compares them without the cast, which is what O2 states
  ("the bands and values the engine offers equal the profile's scale").
- **D2 (ORA2 transcription, sensitivity only).** The SPEC sets option points to K · w · v, with w the
  category's points. When a category is split into several criteria (W5: two rubrics; CAF2020FT and
  Award2025: separately scored factors), this literal rule counts the category's weight once per criterion.
  A sensitivity run divides w by the number of criteria in the category. The **primary result uses the
  literal SPEC rule**; the sensitivity result is reported separately as `sensitivity_D2`.
- **D3 (ORA2, BaldrigeHC2023).** The SPEC says uncertain profiles are observed on O1' and O2'. BaldrigeHC2023
  has no verified scale at all, so there is nothing to transcribe and it is recorded as "not observed".
- **D4 (scoring_v2 probe, W2).** A continuous scale is passed to the engine as one band holding the integers
  0–100, mirroring the ORA2 transcription. O2 is recorded false because a finite list cannot equal a
  continuous scale. The engine itself was not asked to do anything else.
- **D5 (ORA2, official score for frameworks without a total).** For CAF 2020 and CAF2020FT, whose documents
  define no overall score, O3' uses the mean of the element scores, each normalised by its top value (w = 1).
  This is the reading of "w is the category's points (1 when the profile gives none)" applied to the official
  side.
