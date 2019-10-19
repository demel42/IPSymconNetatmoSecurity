<?php

declare(strict_types=1);

/*
 * Problem: bei Netatmo kann man nur für alle Kameras einstellen, wann benachrichtigt/aufgenommen werden soll.
 * Wenn man auf der Straßenseite ggfs. Fahrzeuge aufnehmen möchte, würde aber z.B. im Garten ein Rasenmährobotor
 * als "Fahrzeug" erkannt wird.
 *
 * Mit dieser Beispiel-Logik kann man erzeugte Aufnahme direkt wieder löschen
 */

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';

$instID = $_IPS['InstanceID'];
if ($instID == 24143 /* Garten */) {
    $notifications = json_decode($_IPS['new_notifications'], true);
    foreach ($notifications as $notification) {
        if (isset($notification['event_type'])) {
            $event_type = $notification['event_type'];
            $event_id = $notififaction['id'];
            if ($event_type == 'vehicle') {
                $r = NetatmoSecurity_DeleteEvent($instID, $event_id);
                IPS_LogMessage($scriptName, 'delete event => ' . $event_id . ($r ? 'ok' : 'fail'));
            }
        }
    }
}
