<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/library.php'; // modul-bezogene Funktionen

if (!defined('PRESENCE_ACTION_AWAY')) {
    define('PRESENCE_ACTION_AWAY', 0);
    define('PRESENCE_ACTION_HOME', 1);
    define('PRESENCE_ACTION_ALLAWAY', 2);
}

class NetatmoSecurityPerson extends IPSModule
{
    use NetatmoSecurityCommon;
    use NetatmoSecurityLibrary;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('person_id', '');
        $this->RegisterPropertyString('home_id', '');
        $this->RegisterPropertyString('pseudo', '');

        $associations = [];
        $associations[] = ['Wert' => false, 'Name' => $this->Translate('absent'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => true, 'Name' => $this->Translate('present'), 'Farbe' => -1];
        $this->CreateVarProfile('NetatmoSecurity.Presence', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 1, '', $associations);

        $associations = [];
        $associations[] = ['Wert' => PRESENCE_ACTION_AWAY, 'Name' => $this->Translate('away'), 'Farbe' => -1];
        $associations[] = ['Wert' => PRESENCE_ACTION_HOME, 'Name' => $this->Translate('home'), 'Farbe' => -1];
        $associations[] = ['Wert' => PRESENCE_ACTION_ALLAWAY, 'Name' => $this->Translate('all away'), 'Farbe' => -1];
        $this->CreateVarProfile('NetatmoSecurity.PresenceAction', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $vpos = 0;

        $this->MaintainVariable('LastSeen', $this->Translate('Last seen'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('Presence', $this->Translate('Presence'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.Presence', $vpos++, true);
        $this->MaintainVariable('PresenceAction', $this->Translate('Change presence'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.PresenceAction', $vpos++, true);

        $this->MaintainAction('PresenceAction', true);

        $person_id = $this->ReadPropertyString('person_id');
        $pseudo = $this->ReadPropertyString('pseudo');
        $person_info = $person_id . ' (' . $pseudo . ')';
        $this->SetSummary($person_info);

        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
        }
    }

    public function GetConfigurationForm()
    {
        $formElements = [];

        $formElements[] = ['type' => 'CheckBox', 'name' => 'module_disable', 'caption' => 'Instance is disabled'];

        $formElements[] = ['type' => 'Label', 'caption' => 'Netatmo Persons'];

        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'person_id', 'caption' => 'Person-ID'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'home_id', 'caption' => 'Home-ID'];

        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'pseudo', 'caption' => 'Pseudonym'];

        $formActions = [];
        $formActions[] = ['type' => 'Label', 'caption' => '____________________________________________________________________________________________________'];
        $formActions[] = [
                            'type'    => 'Button',
                            'caption' => 'Module description',
                            'onClick' => 'echo "https://github.com/demel42/IPSymconNetatmoSecurity/blob/master/README.md";'
                        ];

        $formStatus = $this->GetFormStatus();

        return json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
    }

    public function ReceiveData($data)
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $jdata = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($jdata, true), 0);

        $source = $jdata['Source'];
        $buf = $jdata['Buffer'];

        $home_id = $this->ReadPropertyString('home_id');
        $person_id = $this->ReadPropertyString('person_id');

        $this->SendDebug(__FUNCTION__, 'source=' . $source, 0);

        if ($buf != '') {
            $jdata = json_decode($buf, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

            switch ($source) {
                case 'QUERY':
                    $homes = $this->GetArrayElem($jdata, 'body.homes', '');
                    if ($homes != '') {
                        foreach ($homes as $home) {
                            if ($home_id != $home['id']) {
                                continue;
                            }
                            $persons = $this->GetArrayElem($home, 'persons', '');
                            if ($persons != '') {
                                foreach ($persons as $person) {
                                    if ($person_id != $person['id']) {
                                        continue;
                                    }
                                    $this->SendDebug(__FUNCTION__, 'decode person=' . print_r($person, true), 0);

                                    $last_seen = $this->GetArrayElem($person, 'last_seen', 0);
                                    $this->SetValue('LastSeen', $last_seen);

                                    $out_of_sight = $this->GetArrayElem($person, 'out_of_sight', false);
                                    $this->SetValue('Presence', !$out_of_sight);
                                    $this->SetValue('PresenceAction', $out_of_sight ? PRESENCE_ACTION_HOME : PRESENCE_ACTION_AWAY);
                                }
                            }
                        }
                    }
                    break;
                case 'EVENT':
                    break;
                default:
                    $err = 'unknown source "' . $source . '"';
                    $this->SendDebug(__FUNCTION__, $err, 0);
                    $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
                    break;
            }
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'PresenceAction':
                $this->SendDebug(__FUNCTION__, $Ident . '=' . $Value, 0);
                $this->SwitchPresence($Value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $Ident, 0);
                break;
        }
    }

    public function SwitchPresence(int $mode)
    {
        $home_id = $this->ReadPropertyString('home_id');
        $person_id = $this->ReadPropertyString('person_id');

        $url = 'https://api.netatmo.com/api/';

        switch ($mode) {
            case PRESENCE_ACTION_AWAY:
                $url .= 'setpersonsaway?home_id=' . rawurlencode($home_id) . '&person_ids=' . '{' . rawurlencode($person_id) . '}' . '&size=1';
                break;
            case PRESENCE_ACTION_HOME:
                $url .= 'setpersonshome?home_id=' . rawurlencode($home_id) . '&person_ids=' . '{' . rawurlencode($person_id) . '}' . '&size=1';
                break;
            case PRESENCE_ACTION_ALLAWAY:
                $url .= 'setpersonsaway?home_id=' . rawurlencode($home_id);
                break;
            default:
                $err = 'unknown mode "' . $mode . '"';
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
                return false;
        }

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrl', 'Url' => $url, 'NeedToken' => true];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        return $jdata['status'];
    }

    public function SetPersonHome()
    {
        return $this->SwitchPresence(PRESENCE_ACTION_HOME);
    }

    public function SetPersonAway()
    {
        return $this->SwitchPresence(PRESENCE_ACTION_AWAY);
    }

    public function SetPersonAllAway()
    {
        return $this->SwitchPresence(PRESENCE_ACTION_ALLAWAY);
    }
}
