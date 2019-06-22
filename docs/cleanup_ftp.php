<?php

$guids = [
        '{06D589CF-7789-44B1-A0EC-6F51428352E6}'
    ];

foreach ($guids as $guid) {
    $instIDs = IPS_GetInstanceListByModuleID($guid);
    foreach ($instIDs as $instID) {
        NetatmoSecurityOutdoor_CleanupVideoPath($instID);
    }
}
