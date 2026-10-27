"""Smoke test with ORA2's own sample rubric only (no study profile)."""
import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ora2_boot import boot, ORA2
boot()
from openassessment.xblock.utils.validation import validate_rubric
from openassessment.assessment.serializers import rubric_from_dict
from openassessment.assessment.models import Assessment, AssessmentPart
rd = json.load(open(os.path.join(ORA2, "src/openassessment/assessment/test/data/rubric/project_plan_rubric.json")))
print("validate_rubric:", validate_rubric(rd, None, False, lambda s: s))
r = rubric_from_dict(rd)
print("points_possible:", r.points_possible)
sel = {c["name"]: max(c["options"], key=lambda o: o["points"])["name"] for c in rd["criteria"]}
a = Assessment.create(r, "smoke", "uuid-smoke", "ST")
AssessmentPart.create_from_option_names(a, sel)
print("points_earned(top):", a.points_earned, "to_float:", a.to_float())
