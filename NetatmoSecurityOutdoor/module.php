<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen

class NetatmoSecurityOutdoor extends IPSModule
{
    use NetatmoSecurityCommon;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('module_id', '');
        $this->RegisterPropertyString('home_id', '');

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $home_id = $this->ReadPropertyString('home_id');
        $module_id = $this->ReadPropertyString('module_id');

        $vpos = 1;
		$module_info = 'abc';
        $this->SetSummary($module_info);

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm()
    {
        $module_type = $this->ReadPropertyString('module_type');

        $formElements = [];

        $formActions = [];
        $formActions[] = ['type' => 'Label', 'label' => '____________________________________________________________________________________________________'];
        $formActions[] = [
                            'type'    => 'Button',
                            'caption' => 'Module description',
                            'onClick' => 'echo "https://github.com/demel42/IPSymconNetatmoSecurity/blob/master/README.md";'
                        ];

        $formStatus = [];
        $formStatus[] = ['code' => IS_CREATING, 'icon' => 'inactive', 'caption' => 'Instance getting created'];
        $formStatus[] = ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => 'Instance is active'];
        $formStatus[] = ['code' => IS_DELETING, 'icon' => 'inactive', 'caption' => 'Instance is deleted'];
        $formStatus[] = ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => 'Instance is inactive'];
        $formStatus[] = ['code' => IS_NOTCREATED, 'icon' => 'inactive', 'caption' => 'Instance is not created'];

        $formStatus[] = ['code' => IS_NODATA, 'icon' => 'error', 'caption' => 'Instance is inactive (no data)'];
        $formStatus[] = ['code' => IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (unauthorized)'];
        $formStatus[] = ['code' => IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (server error)'];
        $formStatus[] = ['code' => IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (http error)'];
        $formStatus[] = ['code' => IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid data)'];
        $formStatus[] = ['code' => IS_NOSTATION, 'icon' => 'error', 'caption' => 'Instance is inactive (no station)'];
        $formStatus[] = ['code' => IS_STATIONMISSІNG, 'icon' => 'error', 'caption' => 'Instance is inactive (station missing)'];

        return json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
    }

    public function ReceiveData($data)
    {
        $jdata = json_decode($data);
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($jdata, true), 0);
        $buf = $jdata->Buffer;

        $home_id = $this->ReadPropertyString('home_id');
        $module_id = $this->ReadPropertyString('module_id');

        $err = '';
        $statuscode = 0;
        $do_abort = false;

        if ($buf != '') {
            $netatmo = json_decode($buf, true);
		}

        $now = time();

        $this->SendDebug(__FUNCTION__, 'netatmo=' . print_r($netatmo, true), 0);
        $this->SendDebug(__FUNCTION__, 'device=' . print_r($device, true), 0);

        $this->SetStatus($statuscode);
    }
}
