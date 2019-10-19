<?php

declare(strict_types=1);

$timeline = '';
$instIDs = IPS_GetInstanceListByModuleID('{06D589CF-7789-44B1-A0EC-6F51428352E6}');
foreach ($instIDs as $instID) {
    $t = NetatmoSecurity_GetTimeline($instID);
    $tag = IPS_GetName($instID);
    $timeline = NetatmoSecurity_MergeTimeline($instID, $timeline, $t, $tag);
}
