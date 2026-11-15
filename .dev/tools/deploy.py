#!/usr/bin/env python3
"""Deployt die Module dieses Repos in eine IP-Symcon-Instanz.

Schreibt die Dateien per PHP (IPS_RunScriptText) nach
<kernelDir>/modules/IPS-Raumfeld/ und prueft die Modulererkennung.

Nutzung:
    python tools/deploy.py            # deployen + pruefen
    python tools/deploy.py --remove   # Modulordner entfernen
"""
from __future__ import annotations

import base64
import json
import sys
import time
import urllib.request
from pathlib import Path

IPS_URL = "http://192.168.10.25:3777/api/"
REPO = Path(__file__).resolve().parent.parent.parent
LIBRARY_DIR = "IPS-Raumfeld"
MODULES = ["RaumfeldDiscovery", "RaumfeldConfigurator", "RaumfeldRenderer", "RaumfeldTile"]
_seq = [0]


def api(method: str, params: list | None = None, timeout: int = 120):
    _seq[0] += 1
    request = urllib.request.Request(
        IPS_URL,
        data=json.dumps({"jsonrpc": "2.0", "id": _seq[0], "method": method, "params": params or []}).encode(),
        headers={"Content-Type": "application/json"},
    )
    response = json.loads(urllib.request.urlopen(request, timeout=timeout).read())
    if "error" in response:
        raise RuntimeError("%s: %s" % (method, response["error"]))
    return response.get("result")


def run_php(code: str, timeout: int = 120) -> str:
    variable_id = api("IPS_CreateVariable", [3])
    api("IPS_SetName", [variable_id, "A0 PHP Out"])
    api("IPS_SetParent", [variable_id, 0])
    try:
        wrapped = (
            'ob_start();\n'
            'try {\n%s\n} catch (Throwable $e) { echo "EXCEPTION: " . $e->getMessage(); }\n'
            '$out = ob_get_clean();\n'
            'SetValue(%d, (string)$out);\n'
        ) % (code, variable_id)
        api("IPS_RunScriptText", [wrapped], timeout=timeout)
        for _ in range(80):
            time.sleep(0.25)
            out = api("GetValue", [variable_id])
            if out:
                return out
        return api("GetValue", [variable_id])
    finally:
        try:
            api("IPS_DeleteVariable", [variable_id])
        except Exception as exc:
            print("cleanup: %s" % exc, file=sys.stderr)


def collect_files() -> list[tuple[str, bytes]]:
    files: list[tuple[str, bytes]] = []
    files.append(("library.json", (REPO / "library.json").read_bytes()))
    for module in MODULES:
        for path in sorted((REPO / module).iterdir()):
            if path.is_file():
                files.append((module + "/" + path.name, path.read_bytes()))
    return files


def deploy() -> None:
    files = collect_files()
    payload = [(name, base64.b64encode(data).decode()) for name, data in files]

    php = "$base = IPS_GetKernelDir() . 'modules/' . %s . '/';\n" % php_string(LIBRARY_DIR)
    php += "$files = json_decode(%s, true);\n" % php_string(json.dumps(payload))
    php += "$count = 0;\n"
    php += "foreach ($files as $file) {\n"
    php += "    $target = $base . $file[0];\n"
    php += "    @mkdir(dirname($target), 0775, true);\n"
    php += "    if (file_put_contents($target, base64_decode($file[1])) === false) { echo 'FEHLER ' . $target . \"\\n\"; continue; }\n"
    php += "    $count++;\n"
    php += "}\n"
    php += "echo 'geschrieben: ' . $count . ' Dateien nach ' . $base . \"\\n\";\n"
    php += "$ids = [];\n"
    php += "foreach (['{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C61}','{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C62}','{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C63}'] as $guid) {\n"
    php += "    $ids[] = $guid . '=' . (in_array($guid, IPS_GetModuleList()) ? 'JA' : 'NEIN');\n"
    php += "}\n"
    php += "echo implode(\"\\n\", $ids) . \"\\n\";\n"

    print(run_php(php, timeout=180))


def remove() -> None:
    php = "$dir = IPS_GetKernelDir() . 'modules/' . %s . '/' ;\n" % php_string(LIBRARY_DIR)
    php += "if (!is_dir($dir)) { echo 'nicht vorhanden'; exit; }\n"
    php += "$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);\n"
    php += "foreach ($it as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }\n"
    php += "@rmdir($dir);\n"
    php += "echo 'entfernt: ' . $dir . \"\\n\";\n"
    print(run_php(php, timeout=120))


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


if __name__ == "__main__":
    if "--remove" in sys.argv:
        remove()
    else:
        deploy()
