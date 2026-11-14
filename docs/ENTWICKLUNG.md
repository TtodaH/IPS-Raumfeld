# Entwicklung

## Aufbau der Module

Jedes Modul liegt in einem eigenen Ordner und besteht aus `module.json` und
`module.php`. Die Klasse in `module.php` muss exakt so heißen wie `name` in
`module.json` (Leerzeichen entfallen).

| Modul | Typ | GUID |
| --- | --- | --- |
| Raumfeld Discovery | 5 (Discovery) | `{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C61}` |
| Raumfeld Configurator | 4 (Configurator) | `{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C62}` |
| Raumfeld Renderer | 3 (Device) | `{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C63}` |

Die GUIDs sind fest und dürfen nicht geändert werden – IP-Symcon erkennt
bestehende Instanzen darüber.

## Modulbasis

Alle Module erben von `IPSModuleStrict` (ab IP-Symcon 8.1). Wichtig:

- Alle öffentlichen Methoden brauchen Typdeklarationen (`: void`, `: string`).
- `GetConfigurationForm(): string` liefert JSON als **String**, nicht als Array.
- `RequestAction(string $Ident, mixed $Value): void` behandelt Variablenaktionen.
- Öffentliche Methoden ohne `_` im Namen werden als Modulfunktionen exportiert
  und erhalten das Präfix aus `module.json` (`RFR_`, `RFC_`, `RFD_`).

## HTTP im Modul

| Zweck | Umsetzung |
| --- | --- |
| GET auf Host-API | `Sys_GetURLContentEx($url, ['Timeout' => 5000])` |
| POST (SOAP) | `curl_init` / `curl_setopt_array` / `curl_exec` |
| XML lesen | `simplexml_load_string` + `xpath('//*[local-name()="..."]')` |

`Sys_GetURLContentEx` unterstützt kein POST, daher läuft SOAP über cURL.
Die Namespace-tolerante XPath-Variante ist nötig, weil die Geräte
Standard-Namespaces verwenden.

## Dynamischer Renderer-Port

Der SOAP-Port des Renderers ändert sich bei jedem Geräteneustart. Deshalb:

1. `listDevices` vom Host lesen.
2. Die `location` des Renderers über die UDN suchen.
3. Nur Schema, Host und Port daraus als Basis-URL verwenden.

Das Ergebnis wird im Buffer `RendererLocation` gehalten und bei `ApplyChanges`
neu ermittelt.

## Lokale Prüfung

```bash
# Syntax aller Module
for f in */module.php; do php -l "$f"; done
```

## Live-Test

`tests/harness.php` lädt den echten Modulcode gegen einen Stub und spricht ein
reales Gerät an. Der Stub ersetzt `IPSModuleStrict`, weil die echte Klasse in
IP-Symcon einen Instanz-Konstruktor verlangt.

```bash
python tools/test_live.py 192.168.10.18
```

Der Test prüft:

- Host-Ping und Modellname
- Zonen- und Renderer-Erkennung (inkl. `unassignedRooms`)
- Configurator-Zeilen mit `create`-Block
- Lautstärke lesen, setzen, zurücklesen
- Mute umschalten
- Transportzustand, Play/Stop, Wake
- Fehlerfälle (unerreichbarer Host, leere Konfiguration)

## Deployment in eine laufende Instanz

```bash
python tools/deploy.py
```

Das Skript schreibt alle Moduldateien per JSON-RPC nach
`<kernelDir>/modules/IPS-Raumfeld/`. IP-Symcon muss danach neu gestartet
werden, damit die Modulliste die neuen GUIDs enthält.

```bash
python tools/deploy.py --remove
```

## IP-Symcon-API-Hinweise

Die JSON-RPC-API unter `http://<ips>:3777/api/` bietet unter anderem:

| Methode | Zweck |
| --- | --- |
| `IPS_GetKernelVersion` | Version lesen |
| `IPS_GetKernelDir` | Kernelverzeichnis |
| `IPS_GetModuleList` | alle bekannten Modul-GUIDs |
| `IPS_CreateVariable` | Variable anlegen (3 = String) |
| `IPS_CreateScript` / `IPS_SetScriptContent` | Skript anlegen |
| `IPS_RunScriptText` | PHP direkt ausführen |
| `IPS_RunScript` | Skript ausführen |
| `GetValue` / `SetValue` | Variablenwert lesen/schreiben |
| `IPS_DeleteVariable` / `IPS_DeleteScript` | aufräumen |

`IPS_RunScriptText` liefert nur `true` zurück. Für Ausgaben wird eine
temporäre String-Variable beschrieben und per `GetValue` gelesen.

## Neue Aktionen ergänzen

1. Öffentliche Methode im Renderer anlegen (z. B. `Seek`).
2. SOAP-Aufruf über `RFSoapAV(...)` ergänzen.
3. Optional Button in `GetConfigurationForm()` unter `actions` hinzufügen.
4. `php -l` und `tools/test_live.py` ausführen.
5. README-Tabelle der Funktionen aktualisieren.
