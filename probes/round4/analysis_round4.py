"""Round 4, step 3: the analysis fixed in SPEC.md, computed from the committed observation files only.

Per engine (round-4 primary set): accuracy with Clopper-Pearson exact 95% CI; exact two-sided McNemar of the
condition against each baseline (majority of the round, all-configuration, Baldrige-family).
Pooled rounds 1-4 (every prediction made before observation): exact McNemar against all-configuration and
the family rule, (a) authors' platform only, (b) both engines. Pooled units are not independent (SPEC).

Output: analysis_round4.json. Standard library only.
"""
import json
from math import comb
from pathlib import Path

HERE = Path(__file__).resolve().parent
PROBES = HERE.parent
CONF = "configuration only"


def jload(p):
    return json.loads(Path(p).read_text(encoding="utf-8"))


def binom_cdf(k, n, p):
    return sum(comb(n, i) * p ** i * (1 - p) ** (n - i) for i in range(k + 1))


def clopper_pearson(x, n, alpha=0.05):
    def solve(f):
        lo, hi = 0.0, 1.0
        for _ in range(200):
            mid = (lo + hi) / 2
            lo, hi = (mid, hi) if f(mid) else (lo, mid)
        return (lo + hi) / 2
    lower = 0.0 if x == 0 else solve(lambda p: 1 - binom_cdf(x - 1, n, p) < alpha / 2)
    upper = 1.0 if x == n else solve(lambda p: binom_cdf(x, n, p) > alpha / 2)
    return round(lower, 4), round(upper, 4)


def mcnemar(units, base):
    b = sum(1 for u in units if u["pred"] == u["obs"] and u[base] != u["obs"])
    c = sum(1 for u in units if u["pred"] != u["obs"] and u[base] == u["obs"])
    n, k = b + c, min(b, c)
    p = 1.0 if n == 0 else min(1.0, 2 * sum(comb(n, i) for i in range(k + 1)) / 2 ** n)
    return {"b_condition_only_correct": b, "c_baseline_only_correct": c, "p_exact_two_sided": round(p, 6),
            "baseline_correct": sum(1 for u in units if u[base] == u["obs"])}


def family_of():
    meta = jload(HERE / "profiles_round4.json")
    fam = {k: v["baldrige_derived"] for k, v in meta["prior_profiles_meta"].items()}
    fam.update({p["id"]: p["baldrige_derived"] for p in meta["profiles"]})
    return fam


def units_from(rows, fam, label):
    return [{"round": label, "id": r["id"], "pred": r["predicted"], "obs": r["observed"],
             "all_conf": CONF, "family": CONF if fam[r["id"]] else "dedicated module"} for r in rows]


def summary(units, with_majority):
    n = len(units)
    x = sum(1 for u in units if u["pred"] == u["obs"])
    out = {"n": n, "correct": x, "accuracy": round(x / n, 4), "clopper_pearson_95": clopper_pearson(x, n)}
    bases = ["all_conf", "family"]
    if with_majority:
        n_conf = sum(1 for u in units if u["obs"] == CONF)
        maj = CONF if n_conf >= n - n_conf else "dedicated module"
        for u in units:
            u["majority"] = maj
        out["majority_outcome"] = maj
        bases.insert(0, "majority")
    out["mcnemar"] = {b: mcnemar(units, b) for b in bases}
    return out


def main():
    fam = family_of()
    r1 = jload(PROBES / "observations.json")
    r2 = jload(PROBES / "observations_external.json")
    r3 = jload(PROBES / "round3/observations_round3.json")
    ora = jload(HERE / "observations_round4_ora2.json")
    v2 = jload(HERE / "observations_round4_v2.json")
    rows = lambda d: d["profiles"] if isinstance(d, dict) else d  # noqa: E731
    u1, u2, u3 = (units_from(rows(d), fam, k) for d, k in ((r1, "R1"), (r2, "R2"), (r3, "R3")))
    u_ora = units_from([r for r in ora["profiles"] if r["in_primary_set"]], fam, "R4-ORA2")
    u_v2 = units_from(v2["profiles"], fam, "R4-v2")
    sens = [{**u, "obs": r["sensitivity_D2"]["observed"]} for u, r in
            zip(u_ora, [r for r in ora["profiles"] if r["in_primary_set"]])]
    out = {
        "round4_ORA2_primary": summary(u_ora, True),
        "round4_ORA2_sensitivity_D2": summary(sens, True),
        "round4_scoring_v2": summary(u_v2, True),
        "post_hoc_rounds_1_3_pooled": summary(u1 + u2 + u3, False),
        "pooled_rounds_1_4_authors_platform": summary(u1 + u2 + u3 + u_v2, False),
        "pooled_rounds_1_4_both_engines": summary(u1 + u2 + u3 + u_v2 + u_ora, False),
        "notes": ["Pooled units are not independent (same profiles across rounds and engines); descriptive only.",
                  "post_hoc_rounds_1_3_pooled was not pre-registered before rounds 1-3; it is reported as post hoc."],
    }
    (HERE / "analysis_round4.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    for k, v in out.items():
        if k == "notes":
            continue
        m = {b: (r["b_condition_only_correct"], r["c_baseline_only_correct"], r["p_exact_two_sided"], r["baseline_correct"])
             for b, r in v["mcnemar"].items()}
        print(f"{k:38s} {v['correct']}/{v['n']} CI {v['clopper_pearson_95']}  {m}")


if __name__ == "__main__":
    main()
