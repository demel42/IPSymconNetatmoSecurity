# IPSymconNetatmoSecurity

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-5.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Module-Version](https://img.shields.io/badge/Modul_Version-0.9-blue.svg)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![StyleCI](https://github.styleci.io/repos/192195342/shield?branch=master)](https://github.styleci.io/repos/192195342)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

## 2. Voraussetzungen

 - IP-Symcon ab Version 5<br>
 - ein Netatmo Security-Modul (also Kamera oder Rauchmelder)
 - den "normalen" Benutzer-Account, der bei der Anmeldung der Geräte bei Netatmo erzeugt wird (https://my.netatmo.com)
 - einen Account sowie eine "App" bei Netatmo Connect, um die Werte abrufen zu können (https://dev.netatmo.com)<br>
   Achtung: diese App ist nur für den Zugriff auf Netatmo-Security-Produkte gedacht; das Modul benutzt die Scopes _read_presence access_presence read_camera write_camera access_camera read_smokedetector_.
   Eine gleichzeitige Benutzung der gleichen Netatmo-App für andere Bereiche (z.B. Weather) stört sich gegenseitig.<br>
   Die Angabe des WebHook ist nicht erforderlich, das führt das IO-Modul selbst durch.

## 3. Installation

### a. Laden des Moduls

Die Konsole von IP-Symcon öffnen. Im Objektbaum unter Kerninstanzen die Instanz __*Modules*__ durch einen doppelten Mausklick öffnen.

In der _Modules_ Instanz rechts oben auf den Button __*Hinzufügen*__ drücken.

In dem sich öffnenden Fenster folgende URL hinzufügen:

`https://github.com/demel42/IPSymconNetatmoSecurity.git`

und mit _OK_ bestätigen. Ggfs. auf anderen Branch wechseln (Modul-Eintrag editieren, _Zweig_ auswählen).

Anschließend erscheint ein Eintrag für das Modul in der Liste der Instanz _Modules_

### b. Einrichtung in IPS

In IP-Symcon nun unterhalb von _I/O Instanzen_ die Funktion _Instanz hinzufügen_ (_CTRL+1_) auswählen, als Hersteller _Netatmo_ und als Gerät _NetatmoSecurity I/O_ auswählen.

In dem Konfigurationsdialog die Netatmo-Zugangsdaten eintragen.

Dann unter _Konfigurator Instanzen_ analog den Konfigurator _NetatmoSecurity Konfigurator_ hinzufügen.

Hier werden alle Security-Produkte, die mit dem, in der I/O-Instanz angegebenen, Netatmo-Konto verknüpft sind, angeboten; aus denen wählt man ein Produkt aus.

Mit den Schaltflächen _Erstellen_ bzw. _Alle erstellen_ werden das/die gewählne Produkt anlegt.

Zur Zeit werden die Produkttypen
| Produkt-Typ | Bezeichnung               | Modul |
| :---------- | :------------------------ | :---- | 
| NOC         | Outdoor Camera (Presence) | NetatmoSecurityCamera |
| NACamera    | Indoor Camera (Welcome)   | NetatmoSecurityCamera |

unterstützt.

Der Aufruf des Konfigurators kann jederzeit wiederholt werden.

Die Produkte werden aufgrund der _Produkt-ID_ sowie der _Haus-ID_ identifiziert.

Zu den Geräte-Instanzen werden im Rahmen der Konfiguration Produkttyp-abhängig Variablen angelegt. Zusätzlich kann man in den Modultyp-spezifischen Konfigurationsdialog weitere Variablen aktivieren.

Die Instanzen können dann in gewohnter Weise im Objektbaum frei positioniert werden.

## 4. Funktionsreferenz

### NetatmoSecurityIO

`NetatmoSecurityIO_UpdateData(int $InstanzID)`
ruft die Daten der Netatmo-Security-Produkte ab. Wird automatisch zyklisch durch die Instanz durchgeführt im Abstand wie in der Konfiguration angegeben.

### NetatmoSecurityCamera

`NetatmoSecurityCamera_GetVpnUrl(int $InstanzID)`
liefert die externe URL der Kamera zurück

`NetatmoSecurityCamera_GetLocalUrl(int $InstanzID)`
liefert die interne URL der Kamera zurück oder _false_, wenn nicht vorhanden

`NetatmoSecurityCamera_GetLiveVideoUrl(int $InstanzID, string $resolution)`
liefert die (interne oder externe) URL des Live-Videos der Kamera zurück oder _false_, wenn nicht vorhanden.
_resolution_ ist _poor_, _low_, _medium_, _high_.

`NetatmoSecurityCamera_GetLiveSnapshotUrl(int $InstanzID)`
liefert die (interne oder externe) URL des Live-Snapshots der Kamera zurück oder _false_, wenn nicht vorhanden

`NetatmoSecurityCamera_GetVideoUrl(int $InstanzID, string $video_id, string $resolution)`
liefert die (interne oder externe) URL eines gespeicherten Videos zurück oder _false_, wenn nicht vorhanden.
_resolution_ ist _poor_, _low_, _medium_, _high_.

`NetatmoSecurityCamera_GetPictureUrl(int $InstanzID, string $id, string $key)`
liefert die URL eines gespeicherten Bildes (_snapshot_ oder _vignette_) zurück oder _false_, wenn nicht vorhanden

`NetatmoSecurityCamera_GetVideoFilename(int $InstanzID, string $video_id, int $tstamp)`
liefert den Dateiname eines gespeicherten Videos zurück oder _false_, wenn nicht vorhanden (setzt die Übertragung der Videos per FTP voraus)

`NetatmoSecurityCamera_GetEvents(int $InstanzID)`
liefert alle gespeicherten Ereingnisse der Kamera

`NetatmoSecurityCamera_GetNotifications(int $InstanzID)`
liefert alle gespeicherten Benachrichtigungen der Kamera

`NetatmoSecurityCamera_CleanupVideoPath(int $InstanzID, bool $verboѕe = false)`
bereinigt das Verzeichnis der (per FTP übertragenen) Videos

`NetatmoSecurityCamera_SwitchLight(int $InstanzID, int $mode)`
schaltet das Licht (0=aus, 1=ein, 2=auto)

`NetatmoSecurityCamera_DimLight(int $InstanzID, int $intensity)`
dimmt das Licht (0..100%)

`NetatmoSecurityCamera_SwitchCamera(int $InstanzID, int $mode)`
schaltet die Kamera (0=aus, 1=ein)

## 5. Konfiguration

### NetatmoSecurityIO

#### Variablen

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Netatmo-Zugangsdaten      | string   |              | Benutzername und Passwort von https://my.netatmo.com sowie Client-ID und -Secret von https://dev.netatmo.com |
|                           |          |              | |
| Ignoriere HTTP-Fehler     | integer  | 0            | Da Netatmo häufiger HTTP-Fehler meldet, wird erst ab dem X. Fehler in Folge reagiert |
|                           |          |              | |
| Aktualisiere Daten ...    | integer  | 5            | Aktualisierungsintervall, Angabe in Minuten |
|                           |          |              | |
| Webbook registrieren      | boolean  | Nein         | Webhook zur Übernahme der Benachrichtigungen von Netatmo |
| Basis-URL                 |          |              | URL, unter der IPS erreichbar ist; wird nichts angegeben, wird die IPS-Connect-URL verwendet|
|                           |          |              | |
| Aktualisiere Daten ...    | integer  | 5            | Aktualisierungsintervall, Angabe in Minuten |

#### Schaltflächen

| Bezeichnung                  | Beschreibung |
| :--------------------------- | :----------- |
| Aktualisiere Daten           | führt eine sofortige Aktualisierung durch |

### NetatmoSecurityCamera

#### Properties

werden vom Konfigurator beim Anlegen der Instanz gesetzt.

| Eigenschaft              | Typ            | Standardwert | Beschreibung |
| :----------------------- | :------------- | :----------- | :----------- |
| Produkt-Typ              | string         |              | Identifikation, z.Zt _NACamera_, _NOC_ |
| Produkt-ID               | string         |              | ID des Produktes |
| Haus-ID                  | string         |              | ID des "Hauses" |
|                          |                |              | |
| letzte Kommunikation     | UNIX-Timestamp | Nein         | |
| letztes Ereignis         | UNIX-Timestamp | Nein         | |
| letzte Benachrichtigung  | UNIX-Timestamp | Nein         | |
|                          |                |              | |
| Webhook                  | string         |              | Webhook, um Daten dieser Kamera abzufragen |
|                          |                |              | |
| Ereignisse               |                |              | |
|  ... max. Alter          | integer        |              | |
|  ... Medienobjekt cachen | boolean        |              | |
| Benachrichtigung         |                |              | |
|  ... max. Alter          | integer        |              | |
|  ... Medienobjekt cachen | boolean        |              | |
|                          |                |              | |
| FTP-Verzeichnis          |                |              | |
|  ... Verzeichnis         | path           |              | bei relativem Pfad wird IPS-Basisverzeichnis vorangestellt |
|  ... max. Alter          | integer        |              | |
|                          |                |              | |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>

* Integer<br>
NetatmoSecurity.CameraStatus, NetatmoSecurity.CameraAction, NetatmoSecurity.LightModeStatus, NetatmoSecurity.LightAction, NetatmoSecurity.SDCardStatus

* Float<br>

* String<br>

## 6. Anhang

GUIDs
- Modul: `{99D79F62-B7C8-4A59-9A67-65456C6EE9BB}`
- Instanzen:
  - NetatmoSecurityIO: `{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}`
  - NetatmoSecurityConfig: `{C4834515-843B-4B91-A998-6EA29FD9E7A8}`
  - NetatmoSecurityCamera: `{06D589CF-7789-44B1-A0EC-6F51428352E6}`
- Nachrichten:
  - `{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}`: an NetatmoSecurityIO
  - `{5F947426-53FB-4DD9-A725-F95590CBD97C}`: an NetatmoSecurityConfig, NetatmoSecurityCamera

## 7. Versions-Historie

- 1.0 @ dd.mm.yyyy HH:MM<br>
  Initiale Version
