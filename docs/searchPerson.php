<?php

function searchPerson($person_id)
{
    $instID = false;
    $instIDs = IPS_GetInstanceListByModuleID('{7FAAE2B1-D5E8-4E51-9161-85F82EEE79DC}');
    foreach ($instIDs as $id) {
        $persID = IPS_GetProperty($id, 'person_id');
        if ($persID == $person_id) {
            $instID = $id;
            break;
        }
    }
    return $instID;
}
