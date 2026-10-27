"""Round 4, step 2a: observe ORA2 (commit 96b4ad10) on every round-4 ORA2 profile, as fixed in ../SPEC.md.

Run with ORA2's own virtual environment, after `uv sync --frozen --python 3.12` in the ORA2 checkout:
    <ORA2_DIR>/.venv/Scripts/python.exe -I probes/round4/ora2_harness/observe_round4_ora2.py
Output: probes/round4/observations_round4_ora2.json

Primary transcription follows the SPEC literally: option points = K * w * v, with w the category's points
(1 if none). A sensitivity transcription (deviation D2 in ../DEVIATIONS.md) divides w by the number of
criteria the category is split into; it is reported separately and does not replace the primary result.
"""
import hashlib
import json
import os
import sys
from fractions import Fraction
from math import lcm

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ora2_boot import ORA2, boot  # noqa: E402

boot()
from django.db import transaction  # noqa: E402
from openassessment.assessment.models import Assessment, AssessmentPart  # noqa: E402
from openassessment.assessment.serializers import rubric_from_dict  # noqa: E402
from openassessment.xblock.utils.validation import validate_rubric  # noqa: E402

ROUND4 = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROBES = os.path.dirname(ROUND4)
CONF, MOD = "configuration only", "dedicated module"


def jload(p):
    with open(p, encoding="utf-8") as f:
        return json.load(f)


def profiles():
    edpex = jload(os.path.join(PROBES, "profiles.json"))["edpex_scale"]
    r4 = jload(os.path.join(ROUND4, "profiles_round4.json"))
    out = []
    for f in ("profiles.json", "profiles_external.json", "round3/profiles_round3.json"):
        for p in jload(os.path.join(PROBES, f))["profiles"]:
            out.append({**p, **r4["prior_profiles_meta"][p["id"]]})
    return edpex, out + r4["profiles"]


def values(side, edpex):
    sc = edpex if side["scale"] == "edpex" else side["scale"]
    if isinstance(sc, dict):  # continuous: closest finite form = the integers in the range (SPEC)
        lo, hi = sc["continuous"]
        return [Fraction(v) for v in range(int(lo), int(hi) + 1)], True
    vals = sorted({Fraction(str(v)) for band in sc for v in band})
    return vals, False


def elements(p, edpex, split_weight):
    """List of criteria: (category, side, factor, weight, values, continuous)."""
    crit = []
    for n, typ, pts in p["categories"]:
        w = Fraction(pts) if pts else Fraction(1)
        sides = ["results", "process"] if typ == "results+process" else [typ]
        parts = []
        for s in sides:
            vals, cont = values(p[s], edpex)
            separate = p["id"] in ("CAF2020FT", "Award2025")
            for f in (p[s]["factors"] if separate else [None]):
                parts.append((n, s, f, vals, cont))
        for n_, s, f, vals, cont in parts:
            crit.append({"cat": n_, "side": s, "factor": f, "w": w / len(parts) if split_weight else w,
                         "vals": vals, "cont": cont})
    return crit


def rubric_dict(crit):
    K = 1
    for c in crit:
        for v in c["vals"]:
            K = lcm(K, (c["w"] * v).denominator)
    criteria = []
    for i, c in enumerate(crit):
        opts = [{"order_num": j, "points": int(K * c["w"] * v), "name": f"v{v}", "label": str(v), "explanation": ""}
                for j, v in enumerate(c["vals"])]
        criteria.append({"order_num": i, "name": f"c{i}-cat{c['cat']}-{c['side']}" + (f"-{c['factor']}" if c["factor"] else ""),
                         "label": "", "prompt": "probe", "options": opts, "feedback": "disabled"})
    return K, {"prompts": [{"description": "round-4 probe"}], "criteria": criteria}


def official(p, crit, sel):
    """Official normalised score for a selection {criterion index: value or None (not applicable)}."""
    cats = {}
    for i, c in enumerate(crit):
        top = max(c["vals"])
        cats.setdefault(c["cat"], {"w": None, "r": [], "na": False})
        if sel[i] is None:
            cats[c["cat"]]["na"] = True
        else:
            cats[c["cat"]]["r"].append(Fraction(str(sel[i])) / top)
    weights = {n: (Fraction(pts) if pts else Fraction(1)) for n, _, pts in p["categories"]}
    live = {n: d for n, d in cats.items() if not d["na"]}
    pct = {n: sum(d["r"]) / len(d["r"]) for n, d in live.items()}
    if p["aggregate"] == "min":
        return min(pct.values())
    return sum(weights[n] * pct[n] for n in pct) / sum(weights[n] for n in pct)


def selections(p, crit):
    top = {i: max(c["vals"]) for i, c in enumerate(crit)}
    bot = {i: min(c["vals"]) for i, c in enumerate(crit)}
    mixed = {i: (bot[i] if i % 2 == 0 else top[i]) for i in range(len(crit))}
    if crit[0]["cont"]:
        mixed[0] = Fraction("37.5")
    if p["na_allowed"]:
        mixed[0] = None
    return {"all_top": top, "all_bottom": bot, "mixed": mixed}


def observe(p, edpex, split_weight, uid):
    if any(p[s]["scale"] == "uncertain" for s in ("process", "results")):
        # Deviation D3: no verified scale, so there is nothing to transcribe.
        return {"O1_validates": None, "O2_option_points": None, "O3_scores": None}, ["not observable: scale uncertain"], None
    crit = elements(p, edpex, split_weight)
    K, rd = rubric_dict(crit)
    obs, notes = {}, []
    ok, msg = validate_rubric(rd, None, False, lambda s: s)
    rubric = None
    if ok:
        try:
            rubric = rubric_from_dict(rd)
        except Exception as e:  # noqa: BLE001 - any failure counts against O1'
            ok, msg = False, f"rubric_from_dict: {type(e).__name__}"
    obs["O1_validates"] = bool(ok)
    if not ok:
        notes.append(f"validate_rubric: {msg}")
        obs["O2_option_points"] = False
        obs["O3_scores"] = False
        return obs, notes, K
    stored = {c.name: sorted(o.points for o in c.options.all()) for c in rubric.criteria.all()}
    want_exact = {rd["criteria"][i]["name"]: sorted(K * c["w"] * v for v in c["vals"]) for i, c in enumerate(crit)}
    o2 = all(stored[k] == want_exact[k] for k in want_exact)
    if crit and any(c["cont"] for c in crit):
        o2 = False
        notes.append("scale is continuous; the engine stores a finite list")
    obs["O2_option_points"] = o2
    if p["aggregate"] == "uncertain" or p.get("uncertain"):
        obs["O3_scores"] = None
        notes.append("O3' not defined (uncertain aggregate)")
        return obs, notes, K
    o3 = True
    for name, sel in selections(p, crit).items():
        want = official(p, crit, sel)
        picked = {rd["criteria"][i]["name"]: K * crit[i]["w"] * Fraction(str(v))
                  for i, v in sel.items() if v is not None}
        try:
            with transaction.atomic():
                a = Assessment.create(rubric, "probe", f"{uid}-{name}", "ST")
                AssessmentPart.create_from_option_points(
                    a, {k: (int(v) if v.denominator == 1 else float(v)) for k, v in picked.items()})
                got = a.to_float()
        except Exception as e:  # noqa: BLE001
            o3 = False
            notes.append(f"{name}: {type(e).__name__}")
            continue
        if abs(got - float(want)) > 1e-9:
            o3 = False
            notes.append(f"{name}: engine {got:.6f} vs official {float(want):.6f}")
    obs["O3_scores"] = o3
    return obs, notes, K


def main():
    edpex, profs = profiles()
    preds = {r["id"]: r["predicted"] for r in jload(os.path.join(ROUND4, "predictions_round4.json"))["predictions"]
             if r["engine"] == "ORA2"}
    rows = []
    for p in profs:
        res = {}
        for label, split in (("primary", False), ("sensitivity_D2", True)):
            obs, notes, K = observe(p, edpex, split, f"{p['id']}-{label}")
            defined = [v for v in obs.values() if v is not None]
            res[label] = {"observations": obs, "notes": notes, "K": K,
                          "observed": ("not observed" if not defined else CONF if all(defined) else MOD)}
        pred = preds[p["id"]]
        primary = not pred.startswith("uncertain")
        rows.append({"id": p["id"], "kind": p["kind"], "predicted": pred, "in_primary_set": primary,
                     "observed": res["primary"]["observed"],
                     "prediction_correct": (pred == res["primary"]["observed"]) if primary else None,
                     "primary": res["primary"], "sensitivity_D2": res["sensitivity_D2"],
                     "baldrige_derived": p["baldrige_derived"]})
    lf = lambda f: hashlib.sha256(open(f, "rb").read().replace(b"\r\n", b"\n")).hexdigest()  # noqa: E731
    prim = [r for r in rows if r["in_primary_set"]]
    out = {"round": 4, "engine": "ORA2", "ora2_dir_commit": "96b4ad10b1f1d6108b4440fe0f3b7df0bdb11c34",
           "ora2_models_sha256_lf": lf(os.path.join(ORA2, "src/openassessment/assessment/models/base.py")),
           "predictions_sha256_lf": lf(os.path.join(ROUND4, "predictions_round4.json")),
           "profiles": rows, "correct": sum(r["prediction_correct"] for r in prim), "total": len(prim)}
    with open(os.path.join(ROUND4, "observations_round4_ora2.json"), "w", encoding="utf-8", newline="\n") as f:
        f.write(json.dumps(out, indent=2) + "\n")
    for r in rows:
        tag = "--  " if r["prediction_correct"] is None else ("OK  " if r["prediction_correct"] else "MISS")
        print(f"{r['id']:15s} pred {r['predicted'][:18]:18s} obs {r['observed']:18s} {tag} "
              f"sens={r['sensitivity_D2']['observed'][:4]} {'; '.join(r['primary']['notes'])}")
    print(f"{out['correct']}/{out['total']} primary predictions correct")


if __name__ == "__main__":
    main()
