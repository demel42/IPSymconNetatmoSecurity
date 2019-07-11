<?php

$instIDs = IPS_GetInstanceListByModuleID('{06D589CF-7789-44B1-A0EC-6F51428352E6}');
foreach ($instIDs as $instID) {
    NetatmoSecurity_CleanupVideoPath($instID, false);
}
