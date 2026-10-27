"""Boot ORA2 (pinned commit) on an in-memory SQLite database with ORA2's settings.test (see r4_settings.py).

The ORA2 checkout is located by the ORA2_DIR environment variable (default: ../../../../round4_platform/edx-ora2
relative to this file, i.e. a sibling of the artefact repository, as used by the authors).
"""
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ORA2 = os.path.abspath(os.environ.get("ORA2_DIR", os.path.join(HERE, "..", "..", "..", "..", "round4_platform", "edx-ora2")))
sys.path[:0] = [HERE, ORA2, os.path.join(ORA2, "src")]
os.environ["DJANGO_SETTINGS_MODULE"] = "r4_settings"

import django  # noqa: E402


def boot():
    django.setup()
    from django.core.management import call_command
    call_command("migrate", verbosity=0, run_syncdb=True)
