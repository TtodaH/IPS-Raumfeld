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
und IP-Symcon neu starten.

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

`TransportState` liefert die UPnP-Werte `PLAYING`, `PAUSED_PLAYBACK`,
`STOPPED`, `TRANSITIONING`, `NO_MEDIA_PRESENT`.

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
| `RFR_Wake($id)` | Raum aus Standby wecken |
| `RFR_Standby($id)` | Raum in Standby schicken |

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

## Aufbau

```
IPS-Raumfeld/
  library.json
  RaumfeldDiscovery/     SSDP-Suche, legt Configurator an
  RaumfeldConfigurator/  liest Host-API, legt Renderer an
  RaumfeldRenderer/      SOAP-Steuerung eines Raums
  docs/                  API- und Installationsdokumentation
  tests/harness.php      Live-Test gegen echtes Gerät
  tools/                 Deploy- und Testwerkzeuge
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
