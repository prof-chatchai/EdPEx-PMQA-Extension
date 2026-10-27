"""Round 4, step 1: apply the pre-registered conditions (SPEC.md) before any round-4 observation.

  - C_ORA (Open edX ORA2, commit 96b4ad10b1f1d6108b4440fe0f3b7df0bdb11c34) to all 16 profiles of rounds 1-3
    and the 10 round-4 profiles; every one of them is unobserved on ORA2.
  - C2 (app/scoring_v2.php, unchanged since round 3) to the 10 round-4 profiles only; the 16 earlier
    profiles were already observed on scoring_v2.php in round 3 and are not prospective for it.

A clause that depends on a property marked uncertain is recorded as null and the profile is excluded from the
primary analysis of that engine (SPEC.md). Also prints the best-case power figures stated in SPEC.md, computed
from the committed observations of rounds 1-3 and the round-4 predictions only.

Output: predictions_round4.json (commit before any observation script for round 4 is written or run).
"""
import json
from fractions import Fraction
from math import comb
from pathlib import Path

HERE = Path(__file__).resolve().parent
PROBES = HERE.parent
CONF, MOD, UNC = "configuration only", "dedicated module", "uncertain (excluded from primary analysis)"
ORA2 = {"url": "https://github.com/openedx/edx-ora2", "commit": "96b4ad10b1f1d6108b4440fe0f3b7df0bdb11c34",
        "licence": "AGPL-3.0-only"}


def load():
    edpex = json.loads((PROBES / "profiles.json").read_text(encoding="utf-8"))["edpex_scale"]
    r4 = json.loads((HERE / "profiles_round4.json").read_text(encoding="utf-8"))
    prior = []
    for f in ("profiles.json", "profiles_external.json", "round3/profiles_round3.json"):
        for p in json.loads((PROBES / f).read_text(encoding="utf-8"))["profiles"]:
            prior.append({**p, **r4["prior_profiles_meta"][p["id"]]})
    return edpex, prior, r4["profiles"]


def sides(p):
    used = set()
    for c in p["categories"]:
        used |= {"results", "process"} if c[1] == "results+process" else {c[1]}
    return sorted(used)


def scale_of(side, edpex):
    return edpex if side["scale"] == "edpex" else side["scale"]


def c_ora(p, edpex):
    """C_ORA clauses (traces in SPEC.md). None = depends on an uncertain property."""
    a1 = a2 = True
    for s in sides(p):
        sc = scale_of(p[s], edpex)
        if sc == "uncertain":
            a1 = a2 = None
            break
        if isinstance(sc, dict):  # continuous
            a2 = False
            a1 = a1 and sc["continuous"][0] >= 0
            continue
        vals = [Fraction(str(v)) for band in sc for v in band]
        a1 = a1 and all(v >= 0 for v in vals)
    agg = p["aggregate"]
    a3 = None if agg == "uncertain" else agg in ("weighted_sum", "mean_within_element", "none_defined")
    a4 = not p["na_allowed"]
    return {"A1_nonneg_rational_values": a1, "A2_finite_values_per_element": a2,
            "A3_additive_aggregate": a3, "A4_every_element_rated": a4}


def c2(p, edpex):
    """C2 exactly as pre-registered in round 3 (probes/round3/SPEC.md), extended only to read 'uncertain'
    and continuous scales; C2c is not applicable to a continuous scale (C2b already fails)."""
    cats = p["categories"]
    a = all(c[1] in ("process", "results") for c in cats)
    b, c = True, True
    for s in sides(p):
        sc = scale_of(p[s], edpex)
        if sc == "uncertain" or p[s]["levels"] == "uncertain":
            return {"C2a_one_rubric_per_category": a, "C2b_finite_integer_bands": None, "C2c_bands_not_above_levels": None}
        if isinstance(sc, dict):
            b, c = False, ("n/a" if c is True else c)
            continue
        b &= bool(sc) and all(isinstance(v, int) for band in sc for v in band) and all(band for band in sc)
        if c is not False:
            c = (c in (True, "n/a")) and len(p[s]["factors"]) >= 1 and p[s]["levels"] >= 2 and len(sc) <= p[s]["levels"]
    return {"C2a_one_rubric_per_category": a, "C2b_finite_integer_bands": b, "C2c_bands_not_above_levels": c}


def verdict(cl):
    vals = list(cl.values())
    if any(v is False for v in vals):
        return MOD
    if any(v is None for v in vals):
        return UNC
    return CONF


def row(p, engine, cl):
    return {"id": p["id"], "kind": p["kind"], "engine": engine, "clauses": cl, "predicted": verdict(cl),
            "baseline_all_configuration": CONF,
            "baseline_baldrige_family": CONF if p["baldrige_derived"] else MOD,
            "baseline_majority": "computed from the round-4 observations of this engine (as in rounds 1-3)",
            "uncertain_properties": p.get("uncertain", [])}


def mcnemar_p(b, c):
    n, k = b + c, min(b, c)
    return 1.0 if n == 0 else min(1.0, 2 * sum(comb(n, i) for i in range(k + 1)) / 2 ** n)


def power_note(out):
    """Best case (every round-4 prediction correct) against the all-configuration rule."""
    prior_b = prior_c = 0
    for f in ("observations.json", "observations_external.json", "round3/observations_round3.json"):
        d = json.loads((PROBES / f).read_text(encoding="utf-8"))
        for r in (d["profiles"] if isinstance(d, dict) else d):
            cond_ok, base_ok = r["predicted"] == r["observed"], r["observed"] == CONF
            prior_b += cond_ok and not base_ok
            prior_c += base_ok and not cond_ok
    lines = [f"rounds 1-3 vs all-configuration: b={prior_b} (condition right only), c={prior_c} "
             f"(rule right only), exact McNemar p={mcnemar_p(prior_b, prior_c):.4f}"]
    for eng in ("ORA2", "scoring_v2"):
        k = sum(1 for r in out if r["engine"] == eng and r["predicted"] == MOD)
        lines.append(f"{eng}: {k} primary profiles predicted to fail -> best-case per-engine p={mcnemar_p(k, 0):.4f}")
    k_v2 = sum(1 for r in out if r["engine"] == "scoring_v2" and r["predicted"] == MOD)
    k_all = sum(1 for r in out if r["predicted"] == MOD)
    lines.append(f"pooled rounds 1-4, authors' platform only, best case: p={mcnemar_p(prior_b + k_v2, prior_c):.4f}")
    lines.append(f"pooled rounds 1-4, both engines, best case: p={mcnemar_p(prior_b + k_all, prior_c):.4f}")
    return lines


def main():
    edpex, prior, new = load()
    out = [row(p, "ORA2", c_ora(p, edpex)) for p in prior + new]
    out += [row(p, "scoring_v2", c2(p, edpex)) for p in new]
    notes = power_note(out)
    doc = {"round": 4, "ora2": ORA2, "scoring_v2": "app/scoring_v2.php at tag v1.5.2", "predictions": out,
           "power_best_case": notes}
    (HERE / "predictions_round4.json").write_text(json.dumps(doc, indent=2) + "\n", encoding="utf-8")
    for r in out:
        bad = [k for k, v in r["clauses"].items() if v is False]
        unc = [k for k, v in r["clauses"].items() if v is None]
        print(f"{r['engine']:10s} {r['id']:15s} {r['predicted']:42s} violated={bad or '-'} uncertain={unc or '-'}")
    print("\n".join(notes))


if __name__ == "__main__":
    main()
