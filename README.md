# IPSymconNetatmoSecurity

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

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

Anschluss der Geräte, die von Netatmo unter dem Beriff _Security_ zusammengefasst sind:
- Aussenkamera (_Outdoor_ bzw. _Presence_)
- Innenkamera (_Indoor_ bzw. _Welcome_)
- Rauchmelder
- Fenster- und Türsensoren (_Tags_)
Hinweis: für den Rauchmelder sowiet die Sensoren gibt es mangels eigener Testmöglichkeit noch keine Implementierung.

Je nach Produktyp umfasst das Modul folgende Funktionen:
- Abruf des Status
- Speicherung der Ereignisse für eine definierbaren Zeitraum
- Empfang von Mitteilungen via WebHook
- Ermittlung der URL's zu Abruf von Videos und Snapshots (Live und historisch)
- Einbindung der optional von Netatmo per _FTP_ übertragenen Videos
- Steuerung (Kamera aus/ein, Licht ...)
- Verwaltung der identifizierten Personen sowie Steuerung (_kommt_, _geht_)

## 2. Voraussetzungen

 - IP-Symcon ab Version 6.0
 - ein Netatmo Security-Modul (also Kamera oder Rauchmelder)
 - den "normalen" Benutzer-Account, der bei der Anmeldung der Geräte bei Netatmo erzeugt wird (https://my.netatmo.com)
 - IP-Symcon Connect<br>
   **oder**<br>
 - einen Account sowie eine "App" bei Netatmo Connect, um die Werte abrufen zu können (https://dev.netatmo.com)<br>
   Achtung: diese App ist nur für den Zugriff auf Netatmo-Security-Produkte gedacht; das Modul benutzt die Scopes _read_presence access_presence read_camera write_camera access_camera read_smokedetector_.<br>
   Eine gleichzeitige Benutzung der gleichen Netatmo-App für andere Bereiche (z.B. Weather) stört sich gegenseitig.<br>
   Die Angabe des WebHook in der App-Definition ist nicht erforderlich, das führt das IO-Modul selbst durch.

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *NetatmoSecurity* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/IPSymconNetatmoSecurity` installiert werden.

### b. Einrichtung in IPS

#### NetatmoSecurityIO

In IP-Symcon nun unterhalb von _I/O Instanzen_ die Funktion _Instanz hinzufügen_ auswählen, als Hersteller _Netatmo_ und als Gerät _NetatmoSecurity I/O_ auswählen.
Num muss man im I/O-Modul den Verbindungstyp auswählen

#### über IP-Symcon Connect
_bei Netatmo anmelden_ auswählen und auf der Netatmo Login-Seite Benutzernamen und Passwort eingeben.
Anmerkung: auch wenn hier alle möglichen Netamo-Produkte aufgelistet sind, bezieht sich das Login nur auf die Produkte dieses Moduls.

#### mit eigenem Entwicklerschlüssel
In dem Konfigurationsdialog die Netatmo-Zugangsdaten eintragen.

#### NetatmoSecurityConfig

Dann unter _Konfigurator Instanzen_ analog den Konfigurator _NetatmoSecurity Konfigurator_ hinzufügen.

Hier werden alle Security-Produkte, die mit dem, in der I/O-Instanz angegebenen, Netatmo-Konto verknüpft sind, angeboten; aus denen wählt man ein Produkt aus.

Mit den Schaltflächen _Erstellen_ bzw. _Alle erstellen_ werden das/die gewählte Produkt anlegt.

Zur Zeit werden die folgende Produkttypen unterstützt:

| Produkt-Typ | Bezeichnung               | Modul |
| :---------- | :------------------------ | :---- | 
| NOC         | Outdoor Camera (Presence) | NetatmoSecurityCamera |
| NACamera    | Indoor Camera (Welcome)   | NetatmoSecurityCamera |

Der Aufruf des Konfigurators kann jederzeit wiederholt werden.

Die Produkte werden aufgrund der _Produkt-ID_ sowie der _Heim-ID_ identifiziert.

Zu den Geräte-Instanzen werden im Rahmen der Konfiguration Produkttyp-abhängig Variablen angelegt. Zusätzlich kann man in dem Modultyp-spezifischen Konfigurationsdialog weitere Variablen aktivieren.

Die Instanzen können dann in gewohnter Weise im Objektbaum frei positioniert werden.

## 4. Funktionsreferenz

### NetatmoSecurityIO

`NetatmoSecurity_UpdateData(int $InstanzID)`<br>
ruft die Daten der Netatmo-Security-Produkte ab. Wird automatisch zyklisch durch die Instanz durchgeführt im Abstand wie in der Konfiguration angegeben.

### NetatmoSecurityCamera

#### _Outdoor_ und _Indoor_

`NetatmoSecurity_GetVpnUrl(int $InstanzID)`<br>
liefert die externe URL der Kamera zurück

`NetatmoSecurity_GetLocalUrl(int $InstanzID)`<br>
liefert die interne URL der Kamera zurück oder _false_, wenn nicht vorhanden

`NetatmoSecurity_GetLiveVideoUrl(int $InstanzID, string $resolution, bool $preferLocal)`
liefert die (interne oder externe) URL des Live-Videos der Kamera zurück oder _false_, wenn nicht vorhanden.
_resolution_ hat die Ausprägungen: _poor_, _low_, _medium_, _high_.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll

`NetatmoSecurity_GetLiveSnapshotUrl(int $InstanzID, bool $preferLocal)`<br>
liefert die (interne oder externe) URL des Live-Snapshots der Kamera zurück oder _false_, wenn nicht vorhanden
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll

`NetatmoSecurity_GetVideoUrl(int $InstanzID, string $video_id, string $resolution, bool $preferLocal)`<br>
liefert die (interne oder externe) URL eines gespeicherten Videos zurück oder _false_, wenn nicht vorhanden.
_resolution_ hat die Ausprägungen: _poor_, _low_, _medium_, _high_.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll

`NetatmoSecurity_GetPictureUrl(int $InstanzID, string $id, string $key)`<br>
liefert die URL eines gespeicherten Bildes (_Snapshot_ oder _Vignette_) zurück oder _false_, wenn nicht vorhanden


`NetatmoSecurity_GetVideoFilename(int $InstanzID, string $video_id, int $tstamp)`<br>
liefert den Dateiname eines gespeicherten Videos zurück oder _false_, wenn nicht vorhanden (setzt die Übertragung der Videos per FTP voraus)

`NetatmoSecurity_CleanupVideoPath(int $InstanzID)`<br>
bereinigt das Verzeichnis der (per FTP übertragenen) Videos


`NetatmoSecurity_GetEvents(int $InstanzID)`<br>
liefert alle gespeicherten Ereignisse der Kamera; Datentyp siehe _Events_.
Die Liste ist json-kodiert und zeitlich aufsteigend sortiert.

`NetatmoSecurity_SearchEvent(int $InstanzID, string $event_id)`<br>
Sucht einen Event in den gespeicherten Events

`NetatmoSecurity_SearchSubEvent(int $InstanzID, string $subevent_id)`<br>
Sucht einen Sub-Event in den gespeicherten Events

`NetatmoSecurity_DeleteEvent(int $InstanzID, string $event_id)`<br>
löscht den angegeben Event.
Damit kann man z.B. unerwünscht Ereignisse automatisch löschen. Achtung: das geht leider **nicht** im _Script bei Benachrichtigungen_, da hier die Ereignisse bei netatmo noch nicht vorliegen.
Beispiel siehe [docs/processEventsCleanup.php](docs/processEventsCleanup.php).

`NetatmoSecurity_GetVideoUrl4Event(int $InstanzID, string $event_id, string $resolution, bool $preferLocal)`<br>
Liefert die URL des Videos zu einem bestimmten Event
_resolution_ hat die Ausprägungen: _poor_, _low_, _medium_, _high_.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll

`NetatmoSecurity_GetSnapshotUrl4Event(int $InstanzID, string $event_in, bool $preferLocal)`<br>
Liefert die URL des Snapshot zu einem bestimmten Event.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll

`NetatmoSecurity_GetVignetteUrl4Event(int $InstanzID, string $event_in, bool $preferLocal)`<br>
Liefert die URL der Vignette zu einem bestimmten Event.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll

`NetatmoSecurity_GetSnapshotUrl4Subevent(int $InstanzID, string $subevent_id, bool $preferLocal)`<br>
Liefert die URL des Snapshot zu einem bestimmten Sub-Event.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll
Anmerkung: als Snapshot bezeichnet Netatmo in diesem Zusammenhang das Bild, das zum Erzeugen eines Ereingnisses geführt hat

`NetatmoSecurity_GetVignetteUrl4Subevent(int $InstanzID, string $subevent_id, bool $preferLocal)`<br>
Liefert die URL der Vignette zu einem bestimmten Sub-Event.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll
Anmerkung: als Vignette bezeichnet Netatmo in diesem Zusammenhang den Bildausschnitt, das zum Erzeugen eines Ereingnisses geführt hat


`NetatmoSecurity_GetNotifications(int $InstanzID)`<br>
liefert alle gespeicherten Benachrichtigungen der Kamera; Datentyp siehe _Notifications_.
Die Liste ist json-kodiert und zeitlich aufsteigend sortiert.

`NetatmoSecurity_SearchNotification(int $InstanzID, string $notification_id)`<br>
Sucht eine Notification in den gespeicherten Notifications. Achtung: nicht alle Benachrichtigungen haben eine _id_.

`NetatmoSecurity_GetSnapshotUrl4Notification(int $InstanzID, string $notification_id, bool $preferLocal)`<br>
Liefert die URL des Snapshot zu einer bestimmten Notification.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll

`NetatmoSecurity_GetVignetteUrl4Notification(int $InstanzID, string $notification_id, bool $preferLocal)`<br>
Liefert die URL der Vignette zu einer bestimmten Notification.
_preferLocal_ besagt, ob die lokale oder die öffentliche IP der Kamera benutzt werden soll


`NetatmoSecurity_EventType2Icon(int $InstanzID, string $event_type, bool $asPath)`<br>
liefert das zu einem Ereignistyp passende Icon; mit _asPath_ steuert man, ob der Datenname ist oder der korrekte Pfad geliefert wird.
Beispiel siehe [docs/buildTimeline.php](docs/buildTimeline.php).

`NetatmoSecurity_EventType2Text(int $InstanzID, string $event_type)`<br>
liefert den zu einem Ereignistyp passende Text.


`NetatmoSecurity_WifiStrength2Icon(int $InstanzID, string $event_type, bool $asPath)`<br>
liefert das Icon zur Wifi-Signalstärke

`NetatmoSecurity_WifiStrength2Text(int $InstanzID, string $event_type)`<br>
liefert den zur Wifi-Signalstärke passende Text.


`NetatmoSecurity_GetTimeline(int $InstanzID, bool $withDeleted)`<br>
Zusammenfassung aus den Ereignissen und Benachrichtigungen. Es umfasst alle Ereignisse, von den Benachrichtigungen aber nur die, die
a) bіsher noch nicht zu einem Ereignis wurden
b) die Benachrichtigungen, die nie zu einem Ereignis werden (z.N. Kameraüberwachung ein/aus).
Die Liste ist json-kodiert und zeitlich aufsteigend sortiert.
Der Paranmeter _withDeleted_ sterut, ob Ereignisse, die in der App gelöscht wurden, enthalten sind.

`NetatmoSecurity_MergeTimeline(int $InstanzID, string $total_timeline, string $add_timeline, string $tag)`<br>
Fügt _add_timeline_ der _total_timeline_ hinzu und versieht alle neuen Einträge mit dem Element _tag_.
Die Liste ist json-kodiert und zeitlich aufsteigend sortiert.
Beispiel siehe [docs/mergeTimelines.php](docs/mergeTimelines.php).


`NetatmoSecurity_SwitchCamera(int $InstanzID, int $mode)`<br>
schaltet die Kamera (0=aus, 1=ein)


`NetatmoSecurity_GetServerUrl(int $InstanzID)`<br>
liefert die öffentliche (_ipmagic_) oder die lokale URL des Servers

#### _Outdoor_

`NetatmoSecurity_SwitchLight(int $InstanzID, int $mode)`<br>
schaltet das Licht (0=aus, 1=ein, 2=auto)

`NetatmoSecurity_DimLight(int $InstanzID, int $intensity)`<br>
stellt die Intensität das Lichtes ein (0..100%). <br>


`NetatmoSecurity_LoadTimelapse(int $InstanzID)`<br>
Herstellt und lädt die Netatmo-Zeitraffer-Darstellung für die zurückliegenden 24h.
Als Bezugszeitpunkt (für die Suche danach) gilt immer der Tag, ab dem die 24h beginnen.
D.h. das Video wird immer unter dem Datum des Vortags gespeichert.

`NetatmoSecurity_GetTimelapseFilenamel(int $InstanzID, int $refdate = 0)`<br>
Ermittlung der Datei mit der Zeitrafferdarstellung des angegebenen Referenzdatums

`NetatmoSecurity_GetTimelapseUrl(int $InstanzID, int $refdate = 0, bool $preferLocal)`<br>
Ermittlung der URL zu der Zeitrafferdarstellung des angegebenen Referenzdatums

`NetatmoSecurity_CleanupTimelapsePath(int $InstanzID)`<br>
bereinigt das Verzeichnis der Zeitraffer-Darstellungen


### NetatmoSecurityPerson

`NetatmoSecurity_SetPersonHome(int $InstanzID)`<br>
markiert die Person dieser Instanz als _anwesend_

`NetatmoSecurity_SetPersonAway(int $InstanzID)`<br>
markiert die Person dieser Instanz als _abwesend_

`NetatmoSecurity_SetPersonAllAway(int $InstanzID)`<br>
markiert die Personen der _Heim-ID_ dieser Instalz als _abwesend_

`NetatmoSecurity_GetPersonFaceData(int $InstanzID)`<br>
gibt die _Face_-Daten zurück (Elemente _id_, _key_, _url_).
Hinweis: die Daten stehen erst nach dem ersten Datenabruf zur Verfügung, im Fehlerfall wird _false_ geliefert.

`NetatmoSecurity_GetPersonFaceUrl(int $InstanzID)`<br>
gibt die Url zu dem Bild der Person zurück.

#### WebHook

Das Modul stellt ein WebHook zur Verfügung, mit dem auf die Videos und Bilder zurückgegriffen werden kann (siehe Konfigurationsdialog).

| Kommando                                 | Bedeutung |
| :--------------------------------------- | :-------- |
| video?live                               | liefert die (interne oder externe) URL zu dem Live-Video |
| video?event_id=\<event-id\>              | liefert die URL der lokal gespeicherten MP4-Videodatei oder die (interne oder externe) URL zu dem Video |
|                                          | |
| snapshot?live                            | liefert die (interne oder externe) URL zu dem Live-Snapshot |
| snapshot?subevent_id=\<event-id\>        | liefert die (interne oder externe) URL zu dem Snapshot eines Events |
| snapshot?subevent_id=\<subevent-id\>     | liefert die (interne oder externe) URL zu dem Snapshot eines Sub-Events |
| snapshot?subevent_id=\<notification-id\> | liefert die (interne oder externe) URL zu dem Snapshot einer Notification |
|                                          | |
| vignette?subevent_id=\<event-id\>        | liefert die (interne oder externe) URL zu der Vignette eines Events |
| vignette?subevent_id=\<subevent-id\>     | liefert die (interne oder externe) URL zu der Vignette eines Sub-Events |
| vignette?subevent_id=\<notification-id\> | liefert die (interne oder externe) URL zu der Vignette einer Notification |
|                                          | |
| timelapse                                | liefert die (interne oder externe) URL zu der Zeitrafferdarstellung |

Das _Kommando_ wird an den angegenegen WebHook angehängt.

Bei allen Kommandos vom Typ _video_ kann die Option _resolution=\<resolution\>_ hinzugefügt werden; mögliche Werte sind  _poor_, _low_, _medium_, _high_, Standardwert ist _high_.

Bei allen Kommandos kann Option _result_ angfügt werden

| Option | Beschreibung |
| :----- | :------------| 
| html   | Standardwert, liefert einen kleine HMTL-Code, der per _iframe_ eigebunden werden kann |
| url    | es wird die reine URL, ansonsten ein einbettbarer HTML-Code geliefert |
| custom | es wird das in der Konfiguration angegebene Script aufgerufen. |

Hinweis zu dem _custom_-Script:
Dem Script wird übergeben:
- die _InstanceID_
- die ermittelte _url_ sowie bei dem Aruf des Live-Videos die URL des Live-Snapshots als _alternate_url_
- *_SERVER* als json-kodierter String, benutzen mit
```
$SERVER = json_decode($_IPS['_SERVER'], true);
```
Somit kann z.B.anhand von _$SERVER['HTTP_USER_AGENT']_ Browserspezifische Einstellungen vorgenommen werden
- *_GET* als json-kodierter String, benutzen mit
```
$GET = json_decode($_IPS['_GET'], true);
```
so können diesem Script beliebige Zusatzinformationen übergeben werden.

Das Ergebnis des Scriptes muss mit _echo_ ausgegeben werden und wird als Ergebnis des Webhook ausgegeben.

Ein Muster eines solchen Scriptes finden sich in [docs/processStreamURL.php](docs/processStreamURL.php); das wurde von [Coding Lizard](https://www.symcon.de/forum/members/11676-Coding-Lizard) entwickelt und zur Verfügung gestellt

Hinweis zu dem Video: die lokalen Kopien der Videos werden als MP4 von Netatmo geliefert. Das Abspielen von MP4-Dateien funktioniert nur bei IPS >= 5.2 oder mit dem Firefox-Browser und daher wird unter diesen Umständen die lokale Datei ignoriert.

Dem Kommando vom Type _timelapse_ kann die Option _date=\<refdate\>_ angehängt werden; das Format ist gemäß [strtotime()](https://www.php.net/manual/de/function.strtotime.php). Ohne diese Option wird gestrige Datum angenommen. Die Zeitrafferdarstellung wird als MP4 von Netatmo geliefert; die Einschränkungen der Darstellung gelten wie zuvor beschrieben.


## 5. Konfiguration

### NetatmoSecurityIO

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Verbindungstyp            | integer  | 0            | _Netatmo über IP-Symcon Connect_ oder _Netatmo Entwickler-Schlüssel_ |
|                           |          |              | |
| Netatmo-Zugangsdaten      | string   |              | Benutzername und Passwort von https://my.netatmo.com sowie Client-ID und -Secret von https://dev.netatmo.com |
|                           |          |              | |
| Aktualisiere Daten ...    | integer  | 5            | Aktualisierungsintervall, Angabe in Minuten |
|                           |          |              | |
| Anzahl Ereignisse ...     | integer  | 30           | Anzahl der Ereignisse die bei einem Update abgerufen werden |
|                           |          |              | |
| Webbook registrieren      | boolean  | Nein         | Webhook zur Übernahme der Benachrichtigungen von Netatmo |
| Basis-URL                 | string   |              | URL, unter der IPS erreichbar ist; wird nichts angegeben, wird die IPS-Connect-URL verwendet|
|                           |          |              | |
| Aktualisiere Daten ...    | integer  | 5            | Aktualisierungsintervall, Angabe in Minuten |

Hinweise zu _Anzahl Ereignisse_: Ereignisse, die nachträglich vom Benutzer in der Netatmo-App gelöscht werden, werden in IPS als gelöscht markiert. Um gelöschte Ereignisse erkennen zu können, muss eine ausreichende Menge an Ereignisse abgerufen werden; wie viele, hängt davon ab, wieviele Ereignisse stattfinden. Die Standardanzahl vom *30* reicht im Regelfall aus und sollte nur vorsichtig erhöht werden.

#### Schaltflächen

| Bezeichnung                  | Beschreibung |
| :--------------------------- | :----------- |
| bei Netatmo anmelden         | durch Anmeldung bei Netatmo via IP-Symcon Connect |
| Aktualisiere Daten           | führt eine sofortige Aktualisierung durch |
| Webhook registrieren         | registriert den WebHook erneut bei Netatmo |

### NetatmoSecurityConfig

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Kategorie                 | integer  | 0            | Kategorie im Objektbaum, unter dem die Instanzen angelegt werden |
| Produkte                  | list     |              | Liste der verfügbaren Produkte |

### NetatmoSecurityCamera

#### Properties

werden vom Konfigurator beim Anlegen der Instanz gesetzt.

| Eigenschaft              | Typ      | Standardwert | Beschreibung |
| :----------------------- | :--------| :----------- | :----------- |
| Produkt-Typ              | string   |              | Identifikation, z.Zt _NACamera_, _NOC_ |
| Produkt-ID               | string   |              | ID des Produktes |
| Heim-ID                  | string   |              | ID des "Heims" |
|                          |          |              | |
| letzte Kommunikation     | boolean  | Nein         | letzte Kommunikation mit dem Netatmo-Server |
| letztes Ereignis         | boolean  | Nein         | Zeitpunkt der letzten Änderung an Ereignissen durch Ereignis-Abruf |
| letzte Benachrichtigung  | boolean  | Nein         | Zeitpunkt der letzten Benachrichtigung von Netatmo |
| Stärke des Wifi-Signals  | boolean  | Nein         | Ausgabe des Signal in den Abstufungen: _schlecht_, _mittel_, _gut_, _hoch_|
| Bewegungsmelder          | boolean  | Nein         | Variable mit dem Ergebnis des Kamera-Bewegungsmelders |
|                          |          |              | |
| Webhook                  | string   |              | Webhook, um Daten dieser Kamera abzufragen |
|  ... IPS IP-Adresse      | string   |              | DynDNS-Name oder IP des IPS-Servers |
|  ... IPS Portnummer      | integer  | 3777         | Portnummer des IPS-Servers |
|  ... externe IP-Adresse  | string   |              | DynDNS-Name oder IP der externen Adresse des Internet-Anschlusses |
|  ... local CIDR's        | string   |              | durch Semikolog getrennte Liste der lokalen CIDR's (Netzwerke) |
|  ... Script              | integer  |              | Script, das dem Aufruf des WebHook aufgerufen werden kann (siehe Aufbau des WebHook) |
|                          |          |              | |
| Ereignisse               |          |              | |
|  ... max. Alter          | integer  | 14           | automatisches Löschen nach Überschreitung des Alters (in Tagen) |
|  ... Script              | integer  |              | Script, das beim Empfang neuer Ereignisse aufgerufen wird |
|                          |          |              | |
| Benachrichtigung         |          |              | |
|  ... max. Alter          | integer  | 2            | automatisches Löschen nach Überschreitung des Alters (in Tagen) |
|  ... Script              | integer  |              | Script, das beim Empfang einer Benachrichtigung aufgerufen wird |
|                          |          |              | |
| FTP-Verzeichnis          |          |              | |
|  ... Verzeichnis         | string   |              | bei relativem Pfad wird IPS-Basisverzeichnis vorangestellt |
|  ... max. Alter          | integer  | 14           | automatisches Löschen nach Überschreitung des Alters (in Tagen), **0** deaktiviert das Löschen |
|                          |          |              | |
| Zeitraffer-Darstellung   |          |              | **nur bei OutdoorCamera** |
|  ... Verzeichnis         | string   |              | bei relativem Pfad wird IPS-Basisverzeichnis vorangestellt |
|  ... Startzeit           | integer  | 0            | Tageszeit, wann das holen gestartet werden soll, **-1** deaktiviert die Funktion |
|  ... max. Alter          | integer  | 7            | automatisches Löschen nach Überschreitung des Alters (in Tagen), **0** deaktiviert das Löschen |
|                          |          |              | |
| geänderte VPN-URL        |          |              | |
|  ... Script              | integer  |              | Script, das bei Änderung der VPN-URL aufgerufen wird |
|                          |          |              | |
| Personen                 |          |              | **nur bei IndoorCamera** |
| Kategorie                | integer  | 0            | Kategorie im Objektbaum, unter dem die Instanzen angelegt werden |
| Personen                 | list     |              | Liste der verfügbaren Personen zu diesem Heim |

- Hinweis: damit die Videos abgerufen werden können, müssen diesen unterhalb von _webfront/user_ liegen (zumindestens ist mir keine andere Möglichkeit bekannt). Wenn die Daten auf einem anderen Server (z.B. einem NAS) gespeichert werden, so kann das Verzeichnis ja passend gemountet werden.<br>
Das ist an sich unproblatisch, aber die Standard-Sicherung von IPS sichert das Webhook-Verzeichnis natprlich mit und damit wird die Sicherung deutlich größer.

- Warum gibt es die Möglichkeit die per FTP übertragenen Videos einzubinden? Der Zugriff ist schneller und die Darstellung besser, da die Daten nicht von der SD-Karte der Kamera geholt werden müssen.

- Erklärung zu _CIDR_: das ist die Angabe der Adresse und der Maske eines Netzwerks. EIne typische lokalen Netwerk wäre _192.168.178.0/24_ oder _192.168.178.0/255.255.255.0_. Siehe auch https://de.m.wikipedia.org/wiki/Classless_Inter-Domain_Routing.<br>
Die Angabe der externen IP und der lokalen CIDR's dienen zur Ermittlung, ob sich der Client im lokalen Netzwerk befindet und daher auf die lokalen Adresse der Kamera zugreifen kann oder die VPN-URL's von Netatmo verwendet werden muss. Ist nichts angegeben, wird angenommen, das ein Aufruf über die _http.://xxx.ipmagic.de_ immer von extern kommt.

- Script bei Benachrichtigungen: das Script wird aufgerufen, wenn eine Benachrichtigung eingetroffen ist und verarbeitet wurde.<br>
Es dient dazu, bei einer Benachrichtigung direkt eine Meldung, z.B. ein _WFC_SendNotification()_ aufzurufen.
Dem Script wird neben der _InstanceID_ auch die neuen Benachrichtigungen als json-kodierte String in _new_notifications_ übergeben.<br>
Ein passendes Code-Fragment für ein Script zur Erstellung einer HTML-Box mit den Benachrichtigungen siehe _docs/processNotification.php_
Wichtig: die Laufzeit dieses Scriptes sollte so kurz wiel möglich sein.
Nachdem eine Benachrichtigung empfangen wurde, wird automatisch nach 5 Sekunden ein Datenabgleich angefordert und damit die Ereignisliste zeitnah vervollständigt.

- Script bei neuen Ereignissen: das Script wird aufgerufen, wenn bei dem zyklischen Abruf von Daten festgestellt wurde, das entweder neue Ereignisse eingetroffen sind oder ein vorhandenes Ereignis verändert oder gelöscht wurde.<br>
Dem Script wird neben der _InstanceID_ auch die neuen Benachrichtigungen als json-kodierte String in _new_events_ übergeben.<br>
Es dient dazu, z.B. eine Tabelle der Ereignisse zu erstelln und in einer HTML-Box abzulegen.

- Script bei geänderter VPN-URL: das Script wird aufgerufen, wenn die VPN-URL bei einem Datenabruf festgestellt wird, das sich die VPN-URL geändert hat (kann jederzeit erfolgen).
Rumpfscript siehe [docs/processUrlChanged.php](docs/processUrlChanged.php).

- Bewegungsmelder
wird gesetzt aufgrund der Ereignisse der Kamera, mögliche Werte:

| Wert | Bedeutung |
| :--- | :-------- |
| 0    | keine Bewegung |
| 1    | Bewegung erkannt |
| 2    | Person erkannt |
| 3    | bekannte Person erkannt (nur bei Innenkamera) |
| 4    | Tier erkannt |
| 5    | Fahrzeug erkannt (nur AUssenkamera) |

Wert wird nach 30 Sekunden auf *keine Bewegung* zurücќ gesetzt

### NetatmoSecurityPersons

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Personen-ID               | string   |              | ID der Person |
| Heim-ID                   | string   |              | ID des "Heim" |
| Pseudonym                 | string   |              | |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>
NetatmoSecurity.Presence,
NetatmoSecurity.YesNo

* Integer<br>
NetatmoSecurity.CameraAction,
NetatmoSecurity.CameraStatus,
NetatmoSecurity.LightAction,
NetatmoSecurity.LightIntensity,
NetatmoSecurity.LightModeStatus,
NetatmoSecurity.MotionType,
NetatmoSecurity.PowerStatus
NetatmoSecurity.SDCardStatus,

### Datenstrukturen

#### Ereignisse (Events)

| Variable          | Datentyp       | optional | Bedeutung |
| :---------------- | :------------- | :------- | :-------- |
| id                | string         | nein     | ID de Ereignisses |
| tstamp            | UNIX-Timestamp | nein     | Zeitpunkt des Ereignisses |
| message           | string         | nein     | Nachrichtentext |
| deleted           | boolean        | ja       | das Ereignis wurde nachträglich (durch den Benutzer) gelöscht |
| video_id          | string         | ja       | |
| video_status      | string         | ja       | Ausprägungen: recording, available, deleted |
| person_id         | string         | ja       | |
| is_arrival        | boolean        | ja       | |
| module_id         | string         | ja       | ID eines zugeordneten Moduls (z.B. DoorTag) |
| subevents         | Objekt-Liste   | ja       | Liste der Einzel-Ereignisse |
|                   |                |          | |
| event_type        | String-Array   | ja       | Zusammnefassung der _event_typ_ der Sub-Events (nur bei _GetTimeline()_) |

#### Einzel-Ereignisse (Sub-Events)

| Variable          | Datentyp       | optional | Bedeutung |
| :---------------- | :------------- | :------- | :-------- |
| id                | string         | ja       | ID des Einzel-Erignisses (siehe Events) |
| tstamp            | UNIX-Timestamp | nein     | Zeitpunkt des Ereignisses |
| event_type        | string         | nein     | Type des Ereignisses |
| message           | string         | nein     | Nachrichtentext |
|                   |                |          | |
| snapshot.id       | string         | ja       | es gibt entweder _id_ + _key_ oder _filename_ |
| snapshot.key      | string         | ja       | |
| snapshot.filename | string         | ja       | |
| vignette.id       | string         | ja       | es gibt entweder _id_ + _key_ oder _filename_ |
| vignette.key      | string         | ja       | |
| vignette.filename | string         | ja       | |

- _event_type_: _human_, _animal_, _vehicle_, _movement_

#### Benachrichtigungen (Notifications)

| Variable          | Datentyp       | optional | Bedeutung |
| :---------------- | :------------- | :------- | :-------- |
| id                | string         | nein     | ID der Benachrichtigung |
| tstamp            | UNIX-Timestamp | nein     | Zeitpunkt der Benachrichtigung |
| push_type         | string         | nein     | Art der Benachrichtigung |
| event_type        | string         | nein     | Art des Ereignisses |
| message           | string         | nein     | Nachrichtentext |
| subevent_id       | string         | ja       | ID des Einzel-Erignisses (siehe _Sub-Events_) |
| snapshot.id       | string         | ja       | siehe _Sub-Events_ |
| snapshot.key      | string         | ja       | siehe _Sub-Events_ |
| vignette.id       | string         | ja       | siehe _Sub-Events_ |
| vignette.key      | string         | ja       | siehe _Sub-Events_ |
| module_id         | string         | ja       | ID eines zugeordneten Moduls (z.B. DoorTag) |
| persons           | Objekt-Liste   | ja       | Liste der Personen |

#### Person

| Variable          | Datentyp       | optional | Bedeutung |
| :---------------- | :------------- | :------- | :-------- |
| person_id         | string         | nein     | ID der Person |
| is_known          | boolean        | ja       | Person ist bekannt |
| face_url          | string         | ja       | URL zu dem Abbild der Person |

- _push_type_:
  - Benachrichtigung mit _Event_ oder _Sub-Event_<br>
	NACamera-movement, NOC-movement,<br>
	NACamera-human, NOC-human, NACamera-person,<br>
	NACamera-animal, NOC-animal,<br>
	NOC-vehicle,<br>
  - sonstige Benachrichtigung:<br>
    connection, NACamera-connection, NOC-connection,<br>
    disconnection, NACamera-disconnection, NOC-disconnection,<br>
    on, NACamera-on, NOC-on,<br>
    off, NACamera-off, NOC-off,<br>
    NOC-light_mode,<br>
    NOC-ftp, ftp-ok, ftp-nok,<br>
    NACamera-alarm_started<br>
    NACamera-tag_big_move, NACamera-tag_small_move,<br>
  - ignorierte Benachrichtigung:<br>
    daily_summary, topology_changed, webhook_activation<br>

## 6. Anhang

GUIDs
- Modul: `{99D79F62-B7C8-4A59-9A67-65456C6EE9BB}`
- Instanzen:
  - NetatmoSecurityIO: `{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}`
  - NetatmoSecurityConfig: `{C4834515-843B-4B91-A998-6EA29FD9E7A8}`
  - NetatmoSecurityCamera: `{06D589CF-7789-44B1-A0EC-6F51428352E6}`
  - NetatmoSecurityPerson: `{7FAAE2B1-D5E8-4E51-9161-85F82EEE79DC}`
  - NetatmoSecurityDetector: `{1E90911D-AB28-5EA7-9134-CCEAF7F48C78}`
- Nachrichten:
  - `{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}`: an NetatmoSecurityIO
  - `{5F947426-53FB-4DD9-A725-F95590CBD97C}`: an NetatmoSecurityConfig, NetatmoSecurityCamera, NetatmoSecurityPerson, NetatmoSecurityDetector

## 7. Versions-Historie

- 1.30.9 @ 11.04.2023 09:34
  - Neu: optionale Anzeige, ob die Kamera mit dem lokalen Netzwerk verbunden ist

- 1.30.8 @ 15.03.2023 09:41
  - Neu: die Events zu "NACamDoorTag" (tag_big_move, tag_small_move) werden nun übernommen und stehen damit in den Notifications und Events zur Verfügung

- 1.30.7 @ 13.03.2023 10:50
  - Fix: Umgang mit Events bei mehr als einem "Home" korrigiert
  - Neu: erste Schritte zur Auswertung von "NACamDoorTag"

- 1.30.6 @ 11.03.2023 06:34
  - Fix: NetatmoSecurityDetector (fehlende Variable)

- 1.30.5 @ 06.03.2023 06:33
  - Fix: NetatmoSecurityDetector

- 1.30.4 @ 28.02.2023 18:05
  - Neu: NetatmoSecurityDetector überarbeitet

- 1.30.3 @ 28.02.2023 14:59
  - Neu: NetatmoSecurityDetector überarbeitet

- 1.30.2 @ 28.02.2023 12:05
  - Neu: Prototyp von NetatmoSecurityDetector

- 1.30.1 @ 28.02.2023 10:30
  - Fix: Absturz im Konfigurator bei Rauchmeldern

- 1.30 @ 24.02.2023 17:25
  - Neu: Vorbereitung zur Übernahme der Daten vom Rauch- und Kohlenmonoxidmelder

- 1.29 @ 05.02.2023 09:29
  - Fix: Bug in GetHomeStatus() bei Anmeldung über Symcon-Connect
  - Neu: Führen einer Statistik der API-Calls im IO-Modul, Anzeige als Popup im Experten-Bereich
  - Fix: Schreibfehler in Kommentaren in Beispiel-Scripten
  - update submodule CommonStubs

- 1.28 @ 19.10.2022 17:34
  - Fix: Verbesserung in MessageSink() um VM_UPDATE-Meldungen zu vermeiden

- 1.27.2 @ 19.10.2022 09:16
  - Fix: README
  - update submodule CommonStubs

- 1.27.1 @ 16.10.2022 13:25
  - Fix: Fehler in 1.27

- 1.27 @ 13.10.2022 10:24
  - Fix: Bezeichnung der Event-Typen in der Netatmo-API hat sich teilweise geändert
    (die Netatmo-Dokumentation entspricht nicht der Realität)

- 1.26.2 @ 12.10.2022 14:44
  - Konfigurator betrachtet nun nur noch Geräte, die entweder noch nicht angelegt wurden oder mit dem gleichen I/O verbunden sind
  - update submodule CommonStubs

- 1.26.1 @ 07.10.2022 13:59
  - update submodule CommonStubs
    Fix: Update-Prüfung wieder funktionsfähig

- 1.26 @ 06.09.2022 16:13
  - Erweiterung: optionale Variable "Bewegungsmelder", wird aufgrund der von der Kamera gemeldeten Ereignisse gesetzt und nach 60s wieder zurück gesetzt.

- 1.25.1 @ 16.08.2022 09:50
  - Fix: Fehler in RequestAction() von NetatmoSecurityPerson
  - update submodule CommonStubs
    Fix: in den Konfiguratoren war es nicht möglich, eine Instanz direkt unter dem Wurzelverzeichnis "IP-Symcon" zu erzeugen

- 1.25 @ 17.07.2022 12:00
  - Fix: das FTP-Verzeichnis wurde unter Umständen nicht aufgeräumt

- 1.24 @ 09.07.2022 12:29
  - einige Funktionen (GetFormElements, GetFormActions) waren fehlerhafterweise "protected" und nicht "private"
  - interne Funktionen sind nun private und ggfs nur noch via IPS_RequestAction() erreichbar
  - Fix: Angabe der Kompatibilität auf 6.2 korrigiert
  - Verbesserung: IPS-Status wird nur noch gesetzt, wenn er sich ändert
  - update submodule CommonStubs
    Fix: Ausgabe des nächsten Timer-Zeitpunkts
    Fix: keine korrekte Registrierung für OAuth

- 1.23.8 @ 17.05.2022 15:38
  - update submodule CommonStubs
    Fix: Absicherung gegen fehlende Objekte

- 1.23.7 @ 16.05.2022 11:45
  - verbessertes Verhalten bei einem HTTP-Fehler

- 1.23.6 @ 10.05.2022 15:06
  - update submodule CommonStubs
  - SetLocation() -> GetConfiguratorLocation()
  - weitere Absicherung ungültiger ID's

- 1.23.5 @ 03.05.2022 15:23
  - Ausgabe der URL's im Experten-Bereich 
  - Korrektur des Netatmo-Webhook (Fehler aus 1.23)

- 1.23.4 @ 01.05.2022 12:42
  - Webhook besser prüfen

- 1.23.3 @ 30.04.2022 09:49
  - Überlagerung von Translate und Aufteilung von locale.json in 3 translation.json (Modul, libs und CommonStubs)

- 1.23.2 @ 26.04.2022 12:37
  - Korrektur: self::$IS_DEACTIVATED wieder IS_INACTIVE
  - IPS-Version ist nun minimal 6.0

- 1.23.1 @ 24.04.2022 10:48
  - Übersetzung vervollständigt

- 1.23 @ 20.04.2022 11:47
  - Implememtierung einer Update-Logik
  - diverse interne Änderungen

- 1.22 @ 16.04.2022 11:21
  - Anpassungen an IPS 6.2 (Prüfung auf ungültige ID's)
  - Konfigurator zeigt nun auch Instanzen an, die nicht mehr zu den vorhandenen Geräten passen
  - Anzeige der Referenzen der Instanz incl. Statusvariablen und Instanz-Timer
  - submodule libs/CommonStubs
  - Korrektur: Speicherung der Informationen für snapshot und vignette in notifications

- 1.21 @ 14.09.2021 16:48
  - Fix: Aufräumen des FTP-Verzeichnisses funktionierte nicht (mehr)

- 1.20 @ 14.07.2021 17:24
  - PHP_CS_FIXER_IGNORE_ENV=1 in github/workflows/style.yml eingefügt
  - docs/buildTimeline.php: korrekte Typ-Konversion
  - Schalter "Instanz ist deaktiviert" umbenannt in "Instanz deaktivieren"

- 1.19 @ 12.09.2020 11:48
  - LICENSE.md hinzugefügt
  - Nutzung von HasActiveParent(): Anzeige im Konfigurationsformular sowie entsprechende Absicherung von SendDataToParent()
  - interne Funktionen sind nun "private"
  - library.php in local.php umbenannt
  - Traits des Moduls haben nun Postfix "Lib"
  - define's durch statische Klassen-Variablen ersetzt
  - Erkennung, ob es der Zugriff auf die Kameras lokal ist, verbessert

- 1.18 @ 17.06.2020 18:52
  - fehlertolerantere Verarbeitung von Daten aus Netatmo im Konfigurator

- 1.17 @ 08.04.2020 12:18
  - define's durch statische Klassen-Variablen ersetzt

- 1.16 @ 06.03.2020 20:14
  - Wechsel des Verbindungstyp wird nun automatisch erkannt
  - Verwendung des OAuth-AccessToken korrigiert

- 1.15 @ 16.02.2020 15:40
  - bei IPS_SHUTDOWN wird DropWebhook() aufgerufen; da das Modul die Events von Netatmo nicht mehr abarbeiten kann,
    würde der WebHook ggfs. für 24h gesperrt werden.

- 1.14 @ 14.02.2020 11:37
  - Bugfix zu 1.23: Zugriff auf Licht funktionierte nicht mehr
  - Funktion in der IO-Konfiguration, um die Token zu löschen

- 1.13 @ 03.02.2020 15:35
  - Ergänzung um die Möglichkeit per OAuth anzumelden<br>
    Achtung: in der IO-Instanz den Verbindungstyp nach dem Update auf _Entwickler-Schlüssel_ setzen!
  - Abfangen von HTTP-Error 406, wenn kein WebHook registriert ist
  - Bugfix: die setperson*-API-Aufrufe sind POST, nicht GET

- 1.12 @ 06.01.2020 11:17
  - Nutzung von RegisterReference() für im Modul genutze Objekte (Scripte, Kategorien etc)
  - SetTimerInterval() erst nach KR_READY

- 1.11 @ 01.01.2020 15:47
  - fix wegen 'strict_types=1'
  - Schreibfehler korrigiert

- 1.10 @ 19.12.2019 14:09
  - Anpassungen an IPS 5.3
    - Formular-Elemente: 'label' in 'caption' geändert

- 1.9 @ 09.12.2019 19:29
  - Auswertung der Statis eines Firmware-Updates

- 1.8 @ 01.12.2019 10:18
  - korrektes Erkennen des Status von SD-Karte
  - Bereitstellen der Icons von SD-Karte (ok, nicht ok), FTP (erfolgreich, fehlerhaft), Kamera (gestartet, verbunden, getrennt)
  - Beispiel-Script docs/buildTimeline.php mit optionalem Video-Autoplay

- 1.7 @ 23.11.2019 11:31
  - beim Systemboot werden die Konfiguration eventuell als ungültig markiert.

- 1.6 @ 13.10.2019 13:18
  - Anpassungen an IPS 5.2
    - IPS_SetVariableProfileValues(), IPS_SetVariableProfileDigits() nur bei INTEGER, FLOAT
    - Dokumentation-URL in module.json
  - Umstellung auf strict_types=1
  - Umstellung von StyleCI auf php-cs-fixer

- 1.5 @ 14.08.2019 12:56
  - push_type "alert" ignorieren
  - Ermittlung der Variable "Status" bezieht nun Kamera-, SD-Karten- und Stromversorgungsstatus mit ein

- 1.4 @ 09.08.2019 14:32
  - zusätzlicher Debug bei der Einrichtung des WebHook
  - NACamera-connected & NACamera-disconnected hinzugefügt
  - opt. Ermittlung der Wifi-Signalstärke

- 1.3 @ 04.08.2019 15:40
  - IP-Symcon IP-Adresse und Portnummer als Konfigurationsfeld vorgesehen zur Unterstützung komplexer Netzwerke, z.B. Docker

- 1.2 @ 01.08.2019 18:29
  - AddWebHook() wird nun jedesmal gemacht, wenn der ApiToken abgelaufen ist.
  - buildTimele.php ergänzt (+ 'max_vignettes', 'autoload')
  - processStreamURL.php ergänzt ('mp4' + controls, autoload)

- 1.1 @ 30.07.2019 19:07
  - Absicherung bei fehlerhafter _ImportCategoryID_
  - Anlage ohne gesetzte Import-Kategorie erfolgt in der Kategorie IP-Symcon/IP-Symcon

- 1.0 @ 25.07.2019 11:01
  - Initiale Version
