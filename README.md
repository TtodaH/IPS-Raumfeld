# IPS-Raumfeld

IP-Symcon-Modul für Teufel Raumfeld Geräte (Sounddeck, Speaker, Multiroom).
Steuert Lautstärke, Stummschaltung und Wiedergabe direkt im lokalen Netz –
ohne Cloud, ohne die offizielle Raumfeld-App.

**Live getestet** gegen ein Teufel Raumfeld Sounddeck (IP-Symcon 9.1, PHP 8.5).

## Warum

Die offizielle Raumfeld-App verbindet sich träge, weil sie den Host auf
Port 47365 mit Long-Polling abfragt. Die Geräte sprechen aber eine einfache,
vollständig lokale HTTP- und UPnP/SOAP-Schnittstelle. Dieses Modul nutzt sie
direkt.

## Installation

### Über die Module Control (empfohlen)

1. In IP-Symcon die Instanz **Modules** öffnen (Kern-Instanzen).
2. Über **+** die Git-URL eintragen:
   `https://github.com/TtodaH/IPS-Raumfeld`
3. Die Bibliothek **IPS-Raumfeld** erscheint im Objektbaum.

### Manuell

Den Ordner nach `<IP-Symcon-Kernelverzeichnis>/modules/IPS-Raumfeld/` kopieren
und anschliessend die Bibliothek neu laden:

```php
MC_ReloadModule(<ID der Instanz Modules>, 'IPS-Raumfeld');
```

Ein Neustart ist nicht noetig. Wichtig: `library.json` muss das vollstaendige
Format mit `compatibility`, `version`, `build` und `date` enthalten, sonst
werden die `module.json`-Dateien ignoriert (Details in
[docs/ENTWICKLUNG.md](docs/ENTWICKLUNG.md)).

## Einrichtung

1. **Raumfeld Discovery** anlegen (Instanz hinzufügen → Teufel → Raumfeld Discovery).
   - Sucht Hosts per SSDP.
   - Ist Multicast im Netz blockiert, IP-Adresse manuell eintragen.
   - In der Liste den Host über **+** als Configurator anlegen.
2. **Raumfeld Configurator** öffnen.
   - IP-Adresse prüfen (Standard: automatisch übernommen).
   - Die Tabelle zeigt Zone, Raum und Renderer.
   - Über **+** je Raum eine **Raumfeld Renderer**-Instanz anlegen.
3. **Raumfeld Renderer** öffnen.
   - Variablen `Lautstärke`, `Stumm` und `Wiedergabe` erscheinen.
   - Optional Statusabfrage-Intervall setzen (0 = aus).

## Variablen

| Ident | Typ | Profil | Zugriff |
| --- | --- | --- | --- |
| `Volume` | Integer | `~Intensity.100` | lesen/schreiben |
| `Mute` | Boolean | `~Switch` | lesen/schreiben |
| `TransportState` | String | – | nur lesen |
| `PowerState` | String | – | nur lesen |

`TransportState` liefert die UPnP-Werte `PLAYING`, `PAUSED_PLAYBACK`,
`STOPPED`, `TRANSITIONING`, `NO_MEDIA_PRESENT`.

`PowerState` kommt aus `getZones` und zeigt den Betriebszustand des Raums:
`ACTIVE`, `MANUAL_STANDBY` oder `AUTOMATIC_STANDBY`.

## Standby und dynamischer Port

Der SOAP-Port des Renderers ist **dynamisch** und ändert sich nach Standby oder
Neustart des Geräts. Wird die alte Adresse weiterverwendet, reagiert das Gerät
nicht mehr – es wirkt, als müsse es neu gestartet werden.

Das Modul behandelt das automatisch:

1. Bei einem fehlgeschlagenen SOAP-Aufruf wird die Renderer-Adresse über
   `listDevices` neu aufgelöst und der Aufruf einmal wiederholt.
2. `RFR_Reconnect($id)` verwirft den Adress-Cache und baut die Verbindung neu auf.
3. `RFR_Wake($id)` sendet `leaveStandby`; ist der Host nicht erreichbar,
   zusätzlich ein Wake-on-LAN-Paket.
4. `RFR_WakeOnLan($id)` sendet nur das Magic Packet (UDP-Broadcast, Ports 9 und 7).

Für Wake-on-LAN die **MAC-Adresse** in der Instanz eintragen. Sie steht in der
Gerätebeschreibung als `serialNumber` (beim Sounddeck z. B. `ec:24:b8:ec:c5:b7`).

## Funktionen

Im Renderer stehen diese PHP-Funktionen zur Verfügung (Präfix `RFR_`):

| Funktion | Wirkung |
| --- | --- |
| `RFR_Play($id)` | Wiedergabe starten |
| `RFR_Pause($id)` | Pause |
| `RFR_Stop($id)` | Stop |
| `RFR_Next($id)` | nächster Titel |
| `RFR_Previous($id)` | vorheriger Titel |
| `RFR_TogglePlayPause($id)` | Play/Pause umschalten |
| `RFR_UpdateState($id)` | Status neu lesen |
| `RFR_Wake($id)` | Raum aus Standby wecken (inkl. Wake-on-LAN-Fallback) |
| `RFR_Standby($id)` | Raum in Standby schicken |
| `RFR_WakeOnLan($id)` | Magic Packet senden |
| `RFR_Reconnect($id)` | Adresse neu auflösen, Verbindung neu aufbauen |

Im Configurator (Präfix `RFC_`):

| Funktion | Wirkung |
| --- | --- |
| `RFC_Refresh($id)` | Geräteliste neu laden |

## Statuscodes

| Code | Bedeutung |
| --- | --- |
| 102 | aktiv |
| 104 | Host oder Renderer nicht konfiguriert |
| 201 | Host/Renderer nicht erreichbar |
| 202 | Gerät im Standby (`PowerState` ungleich `ACTIVE`) |

## Aufbau

```
IPS-Raumfeld/
  library.json               Bibliothek (vollstaendiges Format, siehe docs)
  RaumfeldDiscovery/
    module.json  module.php  SSDP-Suche, legt Configurator an
  RaumfeldConfigurator/
    module.json  module.php  liest Host-API, legt Renderer an
  RaumfeldRenderer/
    module.json  module.php  form.json   SOAP-Steuerung eines Raums
  docs/                      API-, Installations- und Entwicklerdoku
  tests/harness.php          Live-Test gegen echtes Geraet
  tools/                     Deploy- und Testwerkzeuge
```

## Dokumentation

- [docs/API.md](docs/API.md) – reverse-engineerte Geräteschnittstelle
- [docs/INSTALL.md](docs/INSTALL.md) – Installation und Einrichtung
- [docs/ENTWICKLUNG.md](docs/ENTWICKLUNG.md) – Module entwickeln und testen

## Sicherheitshinweis

Raumfeld-Geräte haben **keine Authentifizierung im lokalen Netz**. Jedes Gerät
im selben Netz kann Lautstärke, Zonen und Standby ändern. Das ist eine
Design-Entscheidung von Raumfeld. Wer das nicht will, sollte die Geräte in ein
eigenes VLAN legen.

## Lizenz

MIT
