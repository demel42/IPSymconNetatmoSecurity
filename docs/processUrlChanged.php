<?php

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';

$instID = $_IPS['InstanceID'];
$vpn_url = NetatmoSecurity_GetVpnUrl($instID);

IPS_LogMessage($scriptName, 'vpn_url=' . $vpn_url);

// hier den Code um eventuell notwendige Änderungen nachzuführen, z.B.

// ID's der HTML-Box-Variablen
 $varIDs = [
        1234,
    ];

foreach ($varIDs as $varID) {
    SetValue($varID, GetValue($varId));
}
