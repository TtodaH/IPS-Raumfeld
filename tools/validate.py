#!/usr/bin/env python3
"""Validiert library.json, module.json und form.json gegen die offiziellen
Schemas des Symcon-Modul-Validators.

Die Schemas liegen unter docs/ref/*Schema.json und stammen von
https://www.symcon.de/assets/files/validation/

Bekannte Abweichung: librarySchema.json erlaubt fuer compatibility.version nur
Werte bis 6.2. Module auf Basis von IPSModuleStrict brauchen jedoch mindestens
8.1 (das Referenzmodul IPS-KNXBusMonitor nutzt ebenfalls 8.1). Diese eine
Abweichung wird als Warnung gemeldet, alles andere als Fehler.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

import jsonschema

REPO = Path(__file__).resolve().parent.parent
SCHEMAS = REPO / "docs" / "ref"


def load_schema(name: str) -> dict:
    return json.loads((SCHEMAS / (name + "Schema.json")).read_text(encoding="utf-8"))


def check(label: str, path: Path, schema: dict) -> tuple[list[str], list[str]]:
    """Liefert (fehler, warnungen)."""
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        return ["%s: kein gueltiges JSON (%s)" % (label, exc)], []

    validator = jsonschema.Draft7Validator(schema)
    errors: list[str] = []
    warnings: list[str] = []

    for error in sorted(validator.iter_errors(data), key=lambda e: list(e.path)):
        location = ".".join(str(p) for p in error.path) or "(root)"
        message = "%s: %s -> %s" % (label, location, error.message)

        # Veraltete Enum im offiziellen Schema: IPSModuleStrict braucht >= 8.1
        if location == "compatibility.version" and isinstance(error.instance, str):
            try:
                if float(error.instance) > 6.2:
                    warnings.append(message + "  [Schema veraltet, funktional korrekt]")
                    continue
            except ValueError:
                pass

        errors.append(message)

    return errors, warnings


def main() -> int:
    errors: list[str] = []
    warnings: list[str] = []

    found, warned = check("library.json", REPO / "library.json", load_schema("library"))
    errors += found
    warnings += warned

    module_schema = load_schema("module")
    form_schema = load_schema("form")

    for module_dir in sorted(p for p in REPO.iterdir() if p.is_dir() and (p / "module.json").exists()):
        name = module_dir.name
        found, warned = check(name + "/module.json", module_dir / "module.json", module_schema)
        errors += found
        warnings += warned

        form = module_dir / "form.json"
        if form.exists():
            found, warned = check(name + "/form.json", form, form_schema)
            errors += found
            warnings += warned

    if warnings:
        print("WARNUNGEN (%d):" % len(warnings))
        for warning in warnings:
            print("  " + warning)

    if errors:
        print("FEHLER (%d):" % len(errors))
        for error in errors:
            print("  " + error)
        return 1

    print("Alle Dateien sind schema-konform.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
