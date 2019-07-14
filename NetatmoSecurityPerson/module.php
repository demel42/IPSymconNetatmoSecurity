<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/library.php'; // modul-bezogene Funktionen

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
        $this->MaintainVariable('Presence', $this->Translate('Presence'), VARIABLETYPE_BOOLEAN, '', $vpos++, true);

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
							if ($persons != '') {
								foreach ($persons as $person) {
									if ($person_id != $person['id']) {
										continue;
									}
									$this->SendDebug(__FUNCTION__, 'decode person=' . print_r($person, true), 0);

									$last_seen = $this->GetArrayElem($person, 'last_seen', 0);
									$this->SetValue('LastSeen', $last_seen);

									$out_of_sight = $this->GetArrayElem($person, 'out_of_sight', false);
									$this->SetValue('Presence', ! $out_of_sight);
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

}
