# Raumfeld Geräteschnittstelle

Reverse-engineerte und **live verifizierte** Schnittstelle eines Teufel Raumfeld
Sounddecks. Alle Angaben wurden gegen ein reales Gerät geprüft
(`Raumfeld Sounddeck`, Firmware-Protokoll 16351, Hardware-Typ 17).

Testgerät: `192.168.10.18`, Raum `Wohnzimmer`.

## Überblick

Ein Raumfeld-Gerät stellt drei Schnittstellen bereit:

| Schnittstelle | Transport | Zweck |
| --- | --- | --- |
| SSDP | UDP Multicast 239.255.255.250:1900 | Host-Suche |
| Host-Web-API | HTTP, Port 47365 | Geräte-, Zonen- und Standby-Verwaltung |
| UPnP/SOAP | HTTP, dynamischer Port | Lautstärke, Mute, Wiedergabe |

## 1. SSDP-Discovery

```
M-SEARCH * HTTP/1.1
HOST: 239.255.255.250:1900
MAN: "ssdp:discover"
ST: urn:schemas-raumfeld-com:device:ConfigDevice:1
MX: 2
```

Die Antwort enthält einen `Location`-Header der Form
`http://<ip>:47365/<uuid>.xml`. Die IP daraus ist der Host.

Weitere Suchtypen:

| ST | Bedeutung |
| --- | --- |
| `urn:schemas-raumfeld-com:device:ConfigDevice:1` | Konfigurationsgerät (Host) |
| `urn:schemas-raumfeld-com:device:RaumfeldDevice:1` | Raumfeld-Gerät |
| `urn:schemas-upnp-org:device:MediaRenderer:1` | Renderer |
| `urn:schemas-upnp-org:device:MediaServer:1` | MediaServer |

## 2. Host-Web-API (Port 47365)

Jeder Client erzeugt eine eigene Session-UUID (32 Hex-Zeichen), die im Pfad steht.

| Endpunkt | Methode | Zweck |
| --- | --- | --- |
| `/Ping` | GET | Heartbeat, liefert `modelName` und `modelNumber` |
| `/{session}/listDevices` | GET | alle Geräte mit `udn`, `type`, `location`, Name |
| `/{session}/getZones` | GET | Zonen, Räume, Renderer, `unassignedRooms` |
| `/leaveStandby?roomUDN=` | GET | Raum aus dem Standby wecken |
| `/enterManualStandby?roomUDN=` | GET | Raum manuell in Standby |
| `/enterAutomaticStandby?roomUDN=` | GET | Raum automatisch in Standby |
| `/connectRoomToZone?roomUDN=&zoneUDN=` | GET | Raum einer Zone zuordnen |
| `/connectRoomsToZone?roomUDNs=a,b&zoneUDN=` | GET | mehrere Räume zuordnen |
| `/dropRoomJob?roomUDN=` | GET | Raum aus seiner Zone lösen |

### Antwort `Ping`

```xml
<?xml version='1.0' encoding='UTF-8'?>
<response>
 <modelName>Raumfeld Sounddeck</modelName>
 <modelNumber>1</modelNumber>
</response>
```

### Antwort `listDevices`

```xml
<devices>
 <device udn='uuid:...' type='urn:schemas-upnp-org:device:MediaRenderer:1'
         location='http://192.168.10.18:52252/....xml'>Speaker Wohnzimmer</device>
 <device udn='uuid:...' type='urn:schemas-upnp-org:device:MediaServer:1'
         location='http://192.168.10.18:50364/....xml'>Raumfeld MediaServer</device>
 <device udn='uuid:...' type='urn:schemas-raumfeld-com:device:RaumfeldDevice:1'
         location='http://192.168.10.18:52380/....xml'>Teufel Raumfeld Device</device>
 <device udn='uuid:...' type='urn:schemas-raumfeld-com:device:ConfigDevice:1'
         location='http://192.168.10.18:52541/....xml'>Raumfeld ConfigDevice</device>
</devices>
```

Die `location` des Renderers enthält den **dynamischen Port**, über den die
SOAP-Dienste erreichbar sind. Dieser Port ändert sich nach einem Neustart des
Geräts – er muss daher bei jeder Verbindung neu aus `listDevices` gelesen werden.

### Antwort `getZones`

```xml
<zoneConfig numRooms='1' spotifyMode='singleRoom'>
 <unassignedRooms>
  <room name='Wohnzimmer' udn='uuid:adfdd731-...'>
   <renderer udn='uuid:23ac20e8-...' name='Speaker Wohnzimmer'></renderer>
  </room>
 </unassignedRooms>
</zoneConfig>
```

Ist ein Raum keiner Zone zugeordnet, erscheint er unter `unassignedRooms`.
Das Modul behandelt diese Räume als eigene Gruppe „Nicht zugeordnet“.

### Raum ohne Zone: beide LEDs leuchten

Ist ein Raum keiner Zone zugeordnet, erscheint er unter `unassignedRooms` und
das Geraet signalisiert das ueber die LEDs (beim Sounddeck leuchten beide).
Das Geraet ist dabei voll erreichbar: `Ping`, `getZones`, Lautstaerke und
Transport antworten normal.

Zuordnung herstellen (ohne `zoneUDN` wird eine neue Zone erzeugt):

```
GET /connectRoomToZone?roomUDN=uuid:adfdd731-504a-4c2d-ba78-336fb5929225
```

Danach steht der Raum unter `<zones>`, `unassignedRooms` verschwindet,
`powerState` wechselt auf `ACTIVE` und die LEDs gehen aus.

Wichtig: Der Renderer-Port aendert sich beim Zonenwechsel (beobachtet
`51331` -> `60736`). IP-Symcon-Instanzen danach einmal neu uebernehmen.

Zonen tragen kein `name`-Attribut. Fuer Anzeigen muss ein Ersatzname gebildet
werden (`Zone 1`, `Zone 2`, ...).

Es gibt **keine** LED-Steuerung in der API. Geprueft wurden RenderingControl,
AVTransport, ConnectionManager, RaumfeldGenerator, SetupService und
ConfigService.

### Long-Polling

`listDevices` und `getZones` unterstützen den Request-Header `updateID`.
Die Antwort enthält einen neuen `updateID`-Header. Bleibt er unverändert,
blockiert der Host die Antwort bis zu einer Änderung. Das erklärt die träge
Verbindung der offiziellen App. Dieses Modul nutzt **kein** Long-Polling,
sondern fragt bei Bedarf neu ab.

## 3. UPnP/SOAP (Renderer)

Die Service-URLs stehen in der Renderer-Beschreibung (`location` aus
`listDevices`):

| Dienst | Control-URL | Service-Typ |
| --- | --- | --- |
| RenderingControl | `/RenderingControl/ctrl` | `urn:schemas-upnp-org:service:RenderingControl:1` |
| AVTransport | `/AVTransport/ctrl` | `urn:schemas-upnp-org:service:AVTransport:1` |
| ConnectionManager | `/ConnectionManager/ctrl` | `urn:schemas-upnp-org:service:ConnectionManager:1` |
| RaumfeldGenerator | `/RaumfeldGenerator/ctrl` | `urn:schemas-raumfeld-com:service:RaumfeldGenerator:1` |

### Aufbau einer SOAP-Anfrage

```http
POST /RenderingControl/ctrl HTTP/1.1
Content-Type: text/xml; charset=utf-8
SOAPACTION: "urn:schemas-upnp-org:service:RenderingControl:1#GetVolume"

<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"
            s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
 <s:Body>
  <u:GetVolume xmlns:u="urn:schemas-upnp-org:service:RenderingControl:1">
   <InstanceID>0</InstanceID>
   <Channel>Master</Channel>
  </u:GetVolume>
 </s:Body>
</s:Envelope>
```

### Verwendete Aktionen

| Aktion | Dienst | Argumente | Antwort |
| --- | --- | --- | --- |
| `GetVolume` | RenderingControl | `InstanceID`, `Channel` | `CurrentVolume` (0–100) |
| `SetVolume` | RenderingControl | `InstanceID`, `Channel`, `DesiredVolume` | – |
| `ChangeVolume` | RenderingControl | `InstanceID`, `Channel`, `Amount` | – |
| `GetMute` | RenderingControl | `InstanceID`, `Channel` | `CurrentMute` (0/1) |
| `SetMute` | RenderingControl | `InstanceID`, `Channel`, `DesiredMute` | – |
| `GetTransportInfo` | AVTransport | `InstanceID` | `CurrentTransportState` |
| `Play` | AVTransport | `InstanceID`, `Speed` | – |
| `Pause` | AVTransport | `InstanceID` | – |
| `Stop` | AVTransport | `InstanceID` | – |
| `Next` | AVTransport | `InstanceID` | – |
| `Previous` | AVTransport | `InstanceID` | – |
| `Seek` | AVTransport | `InstanceID`, `Unit`, `Target` | – |

`Channel` ist am Sounddeck `Master`. `InstanceID` ist `0`; ältere Firmware
verwendet teilweise `1`, daher sollte ein Fallback vorgesehen werden.

### Transport-Zustände

| Wert | Bedeutung |
| --- | --- |
| `PLAYING` | Wiedergabe läuft |
| `PAUSED_PLAYBACK` | pausiert |
| `STOPPED` | gestoppt |
| `TRANSITIONING` | Übergang |
| `NO_MEDIA_PRESENT` | keine Quelle aktiv |

## Verifizierte Messwerte

Vom Testgerät gelesen:

| Wert | Ergebnis |
| --- | --- |
| Modell | Raumfeld Sounddeck |
| Renderer | Speaker Wohnzimmer |
| Raum | Wohnzimmer (unassigned) |
| Lautstärke | 52 |
| Mute | 0 |
| Transport | NO_MEDIA_PRESENT |
| Lautstärke setzen auf 30 | erfolgreich, zurückgelesen 30 |
| Mute umschalten | erfolgreich |

## Sicherheit

Es gibt **keine Authentifizierung**. Jedes Gerät im selben Netz kann die
Steuerungsendpunkte aufrufen. Das betrifft Lautstärke, Stummschaltung,
Wiedergabe, Zonenbildung und Standby.
