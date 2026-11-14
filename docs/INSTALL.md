# Installation und Einrichtung

## Voraussetzungen

| Punkt | Anforderung |
| --- | --- |
| IP-Symcon | ab 8.1 (Modulbasis `IPSModuleStrict`), getestet mit 9.1 |
| PHP | ab 8.0 (getestet mit 8.5) |
| Netzwerk | IP-Symcon und Raumfeld-Gerät im selben LAN |
| Ports | 47365 (Host-API), dynamischer Renderer-Port |

Das Raumfeld-Gerät muss im LAN erreichbar sein. Die Geräte sind nicht über
einen Cloud-Dienst angebunden – das Modul spricht sie direkt an.

## Installation über die Module Control

1. IP-Symcon öffnen.
2. Im Objektbaum unter **Kern-Instanzen** die Instanz **Modules** öffnen.
3. Auf **+** klicken und die Git-URL eintragen:

   ```
   https://github.com/TtodaH/IPS-Raumfeld
   ```

4. Speichern. IP-Symcon klont die Bibliothek und liest `library.json`.
5. Die Module erscheinen anschließend unter **Instanz hinzufügen → Teufel**.

## Manuelle Installation

Für Entwicklung oder wenn kein Git-Zugriff besteht:

```bash
# Zielverzeichnis ist das Kernelverzeichnis der IP-Symcon-Installation
# Linux: /var/lib/symcon/modules/
# Windows: C:\ProgramData\Symcon\modules\

git clone https://github.com/TtodaH/IPS-Raumfeld \
  /var/lib/symcon/modules/IPS-Raumfeld
```

Danach die Bibliothek neu laden:

```php
MC_ReloadModule(<ID der Instanz Modules>, 'IPS-Raumfeld');
```

Die Module erscheinen anschliessend unter **Instanz hinzufuegen -> Teufel**.

Alternativ kann `tools/deploy.py` die Dateien per JSON-RPC-API in eine laufende
Instanz schreiben (siehe [ENTWICKLUNG.md](ENTWICKLUNG.md)).

## Einrichtung in IP-Symcon

### Schritt 1: Discovery

1. **Instanz hinzufügen → Teufel → Raumfeld Discovery**.
2. Die Konfigurationsseite sucht automatisch per SSDP nach Hosts.
3. Wird der Host nicht gefunden (Multicast blockiert), die IP-Adresse im Feld
   **IP-Adresse manuell** eintragen und **Erneut suchen** klicken.
4. In der Tabelle beim gefundenen Host auf **+** klicken. Dadurch entsteht eine
   Configurator-Instanz.

### Schritt 2: Configurator

1. Die neu angelegte Instanz **Raumfeld 192.168.x.y** öffnen.
2. Die Konfigurationsseite zeigt die Tabelle mit Zone, Raum und Renderer.
3. Für jeden Raum auf **+** klicken. Dadurch entsteht eine Renderer-Instanz.

> Räume ohne Zonenzuordnung erscheinen in der Gruppe **Nicht zugeordnet**.
> Sie lassen sich genauso anlegen und steuern.

### Schritt 3: Renderer

1. Die Renderer-Instanz öffnen.
2. Variablen prüfen: **Lautstärke**, **Stumm**, **Wiedergabe**.
3. Optional **Statusabfrage (Sekunden)** setzen:
   - `0` = keine automatische Abfrage (Standard)
   - `10` = alle 10 Sekunden aktualisieren

## Typische Fehler

| Symptom | Ursache | Lösung |
| --- | --- | --- |
| Status 201 | Host/Renderer nicht erreichbar | IP prüfen, gleiches VLAN |
| Status 104 | Konfiguration unvollständig | Host und Renderer-UDN setzen |
| Lautstärke reagiert nicht | Renderer-Port geändert | Instanz neu übernehmen (ApplyChanges) |
| Discovery findet nichts | Multicast blockiert | IP manuell eintragen |
| `NO_MEDIA_PRESENT` | keine Quelle aktiv | erst in der Raumfeld-App/Quelle starten |

## Deinstallation

Renderer- und Configurator-Instanzen löschen, danach die Discovery-Instanz.
Anschließend die Bibliothek in der Module Control entfernen.
