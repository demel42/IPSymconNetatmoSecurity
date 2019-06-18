<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen

class NetatmoSecurityOutdoor extends IPSModule
{
    use NetatmoSecurityCommon;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('product_type', '');
        $this->RegisterPropertyString('product_id', '');
        $this->RegisterPropertyString('home_id', '');

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $product_type = $this->ReadPropertyString('product_type');
        $product_id = $this->ReadPropertyString('product_id');

        $vpos = 1;

        $product_info = $product_id . ' (' . $product_type . ')';
        $this->SetSummary($product_info);

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm()
    {
        $formElements = [];
        $formElements[] = ['type' => 'Label', 'label' => 'Netatmo Outdoor camera (Presence)'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'product_type', 'caption' => 'Product-Type'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'product_id', 'caption' => 'Product-ID'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'home_id', 'caption' => 'Home-ID'];

        $formActions = [];
        $formActions[] = ['type' => 'Label', 'label' => '____________________________________________________________________________________________________'];
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
        $jdata = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($jdata, true), 0);

        $source = $jdata['Source'];
        $buf = $jdata['Buffer'];

        $home_id = $this->ReadPropertyString('home_id');
        $product_id = $this->ReadPropertyString('product_id');

        $err = '';
        $statuscode = 0;
        $do_abort = false;

        $this->SendDebug(__FUNCTION__, 'source=' . $source, 0);

        if ($buf != '') {
            $jdata = json_decode($buf, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
        }

        $now = time();
        $statuscode = IS_ACTIVE;

        $this->SetStatus($statuscode);
    }
}
