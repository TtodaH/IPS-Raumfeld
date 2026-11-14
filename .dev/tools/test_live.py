#!/usr/bin/env python3
"""Fuehrt tests/harness.php auf dem IP-Symcon-Host gegen ein echtes Geraet aus.

Nutzung:
    python tools/test_live.py [HOST-IP]

Standard-Host: 192.168.10.18 (Teufel Raumfeld Sounddeck).
"""
from __future__ import annotations

import base64
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy import php_string, run_php  # noqa: E402

REPO = Path(__file__).resolve().parent.parent
HARNESS = REPO / "tests" / "harness.php"
DEFAULT_HOST = "192.168.10.18"


def main() -> int:
    host = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_HOST
    payload = base64.b64encode(HARNESS.read_bytes()).decode()

    php = "$code = base64_decode(%s);\n" % php_string(payload)
    php += "$tmp = sys_get_temp_dir() . '/rf_harness.php';\n"
    php += "file_put_contents($tmp, $code);\n"
    php += "putenv('RF_HOST=' . %s);\n" % php_string(host)
    php += "require $tmp;\n"

    output = run_php(php, timeout=180)
    print(output)

    if "FAIL" in output or "FERTIG" not in output:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
