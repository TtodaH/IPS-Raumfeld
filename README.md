# Thomas Hellstern

Folgende Module beinhaltet das Thomas Hellstern Repository:

- __Raumfeld Discovery__ ([Dokumentation](RaumfeldDiscovery))  
	Sucht Teufel Raumfeld Hosts im lokalen Netz per SSDP und legt Configurator-Instanzen an.

- __Raumfeld Configurator__ ([Dokumentation](RaumfeldConfigurator))  
	Liest Geräte, Zonen und Räume eines Raumfeld Hosts und legt Renderer-Instanzen an.

- __Raumfeld Renderer__ ([Dokumentation](RaumfeldRenderer))  
	Steuert Lautstärke, Stummschaltung und Wiedergabe eines Raums direkt per UPnP/SOAP.

## Installation

Die Bibliothek über die Module Control einbinden:

```
https://github.com/TtodaH/IPS-Raumfeld
```

Alternativ den Ordner nach `<Kernelverzeichnis>/modules/IPS-Raumfeld/` kopieren und
die Bibliothek neu laden:

```php
MC_ReloadModule(<ID der Instanz Modules>, 'IPS-Raumfeld');
```

## Aufbau

```
IPS-Raumfeld/
  library.json
  README.md
  LICENSE
  RaumfeldDiscovery/      Modul
  RaumfeldConfigurator/   Modul
  RaumfeldRenderer/       Modul
  .dev/                   Entwicklung (Doku, Tests, Werkzeuge)
```

IP-Symcon wertet jeden Unterordner der Bibliothekswurzel als Modul. Ordner ohne
`module.json` machen die Bibliothek ungültig; Entwicklungsdateien liegen deshalb
unter `.dev/`.

## Sicherheitshinweis

Raumfeld-Geräte haben keine Authentifizierung im lokalen Netz. Jedes Gerät im
selben Netz kann Lautstärke, Zonen und Standby ändern. Wer das nicht will, sollte
die Geräte in ein eigenes VLAN legen.

## Lizenz

MIT
