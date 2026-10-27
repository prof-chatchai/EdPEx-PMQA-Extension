"""ORA2 settings.test minus the XBlock workbench UI apps (not installed by `uv sync --frozen`; unrelated to rubric scoring)."""
from settings.test import *  # noqa
INSTALLED_APPS = tuple(a for a in INSTALLED_APPS if a not in ("workbench", "sample_xblocks.basic"))
DATABASES = {"default": {"ENGINE": "django.db.backends.sqlite3", "NAME": ":memory:"}}
