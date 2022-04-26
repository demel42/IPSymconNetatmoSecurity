<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class NetatmoSecurityPerson extends IPSModule
{
    use NetatmoSecurity\StubsCommonLib;
    use NetatmoSecurityLocalLib;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('person_id', '');
        $this->RegisterPropertyString('home_id', '');
        $this->RegisterPropertyString('pseudo', '');

        $this->RegisterAttributeString('UpdateInfo', '');

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $person_id = $this->ReadPropertyString('person_id');
        if ($person_id == '') {
            $this->SendDebug(__FUNCTION__, '"person_id" is empty', 0);
            $r[] = $this->Translate('Person-ID must be specified');
        }

        $home_id = $this->ReadPropertyString('home_id');
        if ($home_id == '') {
            $this->SendDebug(__FUNCTION__, '"home_id" is empty', 0);
            $r[] = $this->Translate('Home-ID must be specified');
        }

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainReferences();

        if ($this->CheckPrerequisites() != false) {
            $this->SetStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->SetStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->SetStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $vpos = 0;

        $this->MaintainVariable('LastSeen', $this->Translate('Last seen'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('Presence', $this->Translate('Presence'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.Presence', $vpos++, true);
        $this->MaintainVariable('PresenceAction', $this->Translate('Change presence'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.PresenceAction', $vpos++, true);

        $this->MaintainAction('PresenceAction', true);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

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

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Netatmo Persons');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'person_id',
            'caption' => 'Person-ID'
        ];
        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'home_id',
            'caption' => 'Home-ID'
        ];

        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'pseudo',
            'caption' => 'Pseudonym'
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    public function ReceiveData($data)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
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

                                    $out_of_sight = (bool) $this->GetArrayElem($person, 'out_of_sight', false);
                                    $this->SetValue('Presence', !$out_of_sight);
                                    $this->SetValue('PresenceAction', $out_of_sight ? self::$PRESENCE_ACTION_HOME : self::$PRESENCE_ACTION_AWAY);

                                    $face = $this->GetArrayElem($person, 'face', '');
                                    $this->SetBuffer('face', json_encode($face));
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

    public function RequestAction($ident, $Value)
    {
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }
        switch ($ident) {
            case 'PresenceAction':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $this->SwitchPresence($value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }

    public function SwitchPresence(int $mode)
    {
        $home_id = $this->ReadPropertyString('home_id');
        $person_id = $this->ReadPropertyString('person_id');

        $url = 'https://api.netatmo.com/api/';

        switch ($mode) {
            case self::$PRESENCE_ACTION_AWAY:
                $url .= 'setpersonsaway';
                $postdata = [
                    'home_id'    => $home_id,
                    'person_id'  => $person_id
                ];
                break;
            case self::$PRESENCE_ACTION_HOME:
                $url .= 'setpersonshome';
                $postdata = [
                    'home_id'    => $home_id,
                    'person_ids' => [
                        $person_id
                    ]
                ];
                break;
            case self::$PRESENCE_ACTION_ALLAWAY:
                $url .= 'setpersonsaway';
                $postdata = [
                    'home_id'   => $home_id
                ];
                break;
            default:
                $err = 'unknown mode "' . $mode . '"';
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
                return false;
        }

        $pdata = json_encode($postdata);
        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlPostWithAuth', 'Url' => $url, 'PostData' => $pdata];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        return $jdata['status'];
    }

    public function SetPersonHome()
    {
        return $this->SwitchPresence(self::$PRESENCE_ACTION_HOME);
    }

    public function SetPersonAway()
    {
        return $this->SwitchPresence(self::$PRESENCE_ACTION_AWAY);
    }

    public function SetPersonAllAway()
    {
        return $this->SwitchPresence(self::$PRESENCE_ACTION_ALLAWAY);
    }

    public function GetPersonFaceData()
    {
        $face = $this->GetBuffer('face');
        return $face != '' ? json_decode($face, true) : false;
    }

    public function GetPersonFaceUrl()
    {
        $face = $this->GetPersonFaceData();
        return isset($face['url']) ? $face['url'] : false;
    }
}
