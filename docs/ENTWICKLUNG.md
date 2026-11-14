# Entwicklung

## Aufbau der Module

Jedes Modul liegt in einem eigenen Ordner. Der Ordnername muss dem Modulnamen
ohne Leerzeichen entsprechen.

| Modul | Typ | GUID | Ordner |
| --- | --- | --- | --- |
| Raumfeld Discovery | 5 (Discovery) | `{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C61}` | `RaumfeldDiscovery` |
| Raumfeld Configurator | 4 (Configurator) | `{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C62}` | `RaumfeldConfigurator` |
| Raumfeld Renderer | 3 (Device) | `{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C63}` | `RaumfeldRenderer` |

Die GUIDs sind fest und dürfen nicht geändert werden – IP-Symcon erkennt
bestehende Instanzen darüber.

## library.json: vollständiges Format ist Pflicht

**Das war die Ursache dafür, dass die Module im Modulmanager erschienen, aber
nicht registriert wurden.** Fehlen `compatibility`, `version`, `build` oder
`date`, liest IP-Symcon die Bibliothek ein, ignoriert aber sämtliche
`module.json` darin. Die Bibliothek taucht dann in `MC_GetModuleList` auf, die
Module fehlen jedoch in `IPS_GetModuleList`.

Pflichtformat:

```json
{
    "id": "{...}",
    "author": "...",
    "name": "IPS-Raumfeld",
    "url": "https://github.com/TtodaH/IPS-Raumfeld",
    "compatibility": { "version": "8.1" },
    "version": "1.0",
    "build": 0,
    "date": 0
}
```

Prüfbefehl nach dem Deployment:

```php
MC_ReloadModule(30416, 'IPS-Raumfeld');   // 30416 = Instanz "Module Control"
count(preg_grep('/A7B3C1D2/', IPS_GetModuleList()));
```

## Modulbasis

Alle Module erben von `IPSModuleStrict` (ab IP-Symcon 8.1). Wichtig:

- Alle öffentlichen Methoden brauchen Typdeklarationen (`: void`, `: string`).
- Variablen werden in `ApplyChanges()` per `MaintainVariable()` angelegt, nicht
  in `Create()` per `RegisterVariable*()`.
- `EnableAction()` gehört ebenfalls in `ApplyChanges()`.
- Persistente Zwischenwerte über `RegisterAttributeString()` /
  `WriteAttributeString()` / `ReadAttributeString()`, nicht über Buffer.
- Status über die Konstanten `IS_ACTIVE` und `IS_INACTIVE`.
- Öffentliche Methoden ohne `_` im Namen werden als Modulfunktionen exportiert
  und erhalten das Präfix aus `module.json` (`RFR_`, `RFC_`, `RFD_`).

## Konfigurationsformular

| Modul | Umsetzung | Grund |
| --- | --- | --- |
| Renderer | `form.json` | statisches Formular |
| Configurator | `GetConfigurationForm()` | dynamische Raumtabelle (`values`/`columns`) |
| Discovery | `GetConfigurationForm()` | dynamische Hostliste |

Sobald `GetConfigurationForm()` implementiert ist, wird `form.json` ignoriert.
Beide Wege sind zulässig; dynamische Tabellen brauchen die PHP-Variante.

## HTTP im Modul

| Zweck | Umsetzung |
| --- | --- |
| GET auf Host-API | `Sys_GetURLContentEx($url, ['Timeout' => 5000])` |
| POST (SOAP) | `curl_init` / `curl_setopt_array` / `curl_exec` |
| XML lesen | `simplexml_load_string` + `xpath('//*[local-name()="..."]')` |

`Sys_GetURLContentEx` unterstützt kein POST, daher läuft SOAP über cURL.
Die namespace-tolerante XPath-Variante ist nötig, weil die Geräte
Standard-Namespaces verwenden.

## Dynamischer Renderer-Port

Der SOAP-Port des Renderers ändert sich bei jedem Geräteneustart. Deshalb:

1. `listDevices` vom Host lesen.
2. Die `location` des Renderers über die UDN suchen.
3. Nur Schema, Host und Port daraus als Basis-URL verwenden.

Das Ergebnis liegt im Attribut `RendererLocation`. Bei einem fehlgeschlagenen
SOAP-Aufruf löst `RFSoapWithRecovery()` die Adresse neu auf und wiederholt den
Aufruf einmal.

## Lokale Prüfung

```bash
for f in */module.php; do php -l "$f"; done
```

## Live-Test

`tests/harness.php` lädt den echten Modulcode gegen einen Stub und spricht ein
reales Gerät an. Der Stub ersetzt `IPSModuleStrict`, weil die echte Klasse in
IP-Symcon einen Instanz-Konstruktor verlangt; zusätzlich stellt er
`MaintainVariable`, `VARIABLETYPE_*`, `IS_ACTIVE` und die Attributmethoden bereit.

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
- Recovery bei veralteter Renderer-Adresse
- `PowerState`
- Fehlerfälle (unerreichbarer Host, leere Konfiguration)

## Deployment in eine laufende Instanz

```bash
python tools/deploy.py
```

Das Skript schreibt alle Moduldateien per JSON-RPC nach
`<kernelDir>/modules/IPS-Raumfeld/`. Danach muss die Bibliothek neu geladen
werden:

```php
MC_ReloadModule(30416, 'IPS-Raumfeld');
```

`tools/deploy.py` prüft die Registrierung direkt nach dem Schreiben.

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
| `IPS_CreateInstance` | Instanz anlegen |
| `IPS_SetProperty` / `IPS_ApplyChanges` | konfigurieren und übernehmen |
| `IPS_GetConfigurationForm` | gerendertes Formular prüfen |
| `IPS_CreateVariable` | Variable anlegen (3 = String) |
| `IPS_RunScriptText` | PHP direkt ausführen |
| `GetValue` / `SetValue` | Variablenwert lesen/schreiben |
| `IPS_DeleteVariable` / `IPS_DeleteScript` | aufräumen |

`IPS_RunScriptText` liefert nur `true` zurück. Für Ausgaben wird eine
temporäre String-Variable beschrieben und per `GetValue` gelesen.

`MC_ReloadModule()` ist eine Funktion der Module-Control-Instanz und erwartet
`(int $InstanceID, string $LibraryName)`.

## Neue Aktionen ergänzen

1. Öffentliche Methode im Renderer anlegen (z. B. `Seek`).
2. SOAP-Aufruf über `RFSoapAV(...)` ergänzen.
3. Button in `form.json` unter `actions` hinzufügen.
4. `php -l` und `tools/test_live.py` ausführen.
5. README-Tabelle der Funktionen aktualisieren.
