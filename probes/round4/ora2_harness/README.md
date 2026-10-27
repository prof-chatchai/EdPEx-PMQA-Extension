# ORA2 harness (round 4)

Reproduce the ORA2 observations:

```
git clone https://github.com/openedx/edx-ora2 && cd edx-ora2
git checkout 96b4ad10b1f1d6108b4440fe0f3b7df0bdb11c34       # AGPL-3.0-only; not vendored here
uv sync --frozen --python 3.12
ORA2_DIR=<path to edx-ora2> <edx-ora2>/.venv/bin/python -I <repo>/probes/round4/ora2_harness/observe_round4_ora2.py
```

`r4_settings.py` is ORA2's `settings.test` with an in-memory SQLite database. It leaves out the XBlock
workbench UI apps, which the frozen install does not provide and which take no part in rubric validation or
scoring. `smoke.py` runs ORA2's own sample rubric only. Then run `python probes/round4/analysis_round4.py`.
