<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/library.php'; // modul-bezogene Funktionen

class NetatmoSecurityIO extends IPSModule
{
    use NetatmoSecurityCommon;
    use NetatmoSecurityLibrary;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('Netatmo_User', '');
        $this->RegisterPropertyString('Netatmo_Password', '');
        $this->RegisterPropertyString('Netatmo_Client', '');
        $this->RegisterPropertyString('Netatmo_Secret', '');

        $this->RegisterPropertyInteger('UpdateDataInterval', '5');
        $this->RegisterPropertyInteger('ignore_http_error', '0');

        $this->RegisterPropertyBoolean('register_webhook', false);
        $this->RegisterPropertyString('webhook_baseurl', '');

        $this->RegisterTimer('UpdateData', 0, 'NetatmoSecurityIO_UpdateData(' . $this->InstanceID . ');');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/NetatmoSecurity');
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->RegisterHook('/hook/NetatmoSecurity');
            $this->UpdateData();

            $register_webhook = $this->ReadPropertyBoolean('register_webhook');
            if ($register_webhook) {
                $this->AddWebhook();
            }
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->SetTimerInterval('UpdateData', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $user = $this->ReadPropertyString('Netatmo_User');
        $password = $this->ReadPropertyString('Netatmo_Password');
        $client = $this->ReadPropertyString('Netatmo_Client');
        $secret = $this->ReadPropertyString('Netatmo_Secret');

        $register_webhook = $this->ReadPropertyBoolean('register_webhook');
        $webhook_baseurl = $this->ReadPropertyString('webhook_baseurl');

        if ($register_webhook) {
            if ($webhook_baseurl == '') {
                $instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
                $url = CC_GetUrl($instID);
                if ($url == '') {
                    $this->SetStatus(IS_NOWEBHOOK);
                    return;
                }
            }

            $instID = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}')[0];
            $hooks = json_decode(IPS_GetProperty($instID, 'Hooks'), true);
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == '/hook/NetatmoSecurity' && $hook['TargetID'] != $this->InstanceID) {
                    $this->SetStatus(IS_DUPWEBHOOK);
                    return;
                }
            }
        }

        if ($user != '' && $password != '' && $client != '' && $secret != '') {
            $this->SetUpdateInterval();
            if (IPS_GetKernelRunlevel() == KR_READY) {
                $this->UpdateData();
            }
            $this->SetStatus(IS_ACTIVE);

            if ($register_webhook) {
                $this->AddWebhook();
            } else {
                $this->DropWebhook();
            }
        } else {
            $this->SetStatus(IS_INACTIVE);
        }
    }

    public function GetConfigurationForm()
    {
        $formElements = [];
        $formElements[] = ['type' => 'CheckBox', 'name' => 'module_disable', 'caption' => 'Instance is disabled'];
        $formElements[] = ['type' => 'Label', 'label' => 'Netatmo Access-Details'];
        $formElements[] = ['type' => 'Label', 'label' => 'Netatmo-Account from https://my.netatmo.com'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_User', 'caption' => 'Username'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_Password', 'caption' => 'Password'];
        $formElements[] = ['type' => 'Label', 'label' => 'Netatmo-Connect from https://dev.netatmo.com'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_Client', 'caption' => 'Client ID'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_Secret', 'caption' => 'Client Secret'];
        $formElements[] = ['type' => 'Label', 'label' => 'Ignore HTTP-Error X times'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'ignore_http_error', 'caption' => 'Count'];

        $formElements[] = ['type' => 'Label', 'label' => 'Webhook for receive events from Netatmo (must be reachable from internet)'];
        $formElements[] = ['type' => 'CheckBox', 'name' => 'register_webhook', 'caption' => 'Register Webhook'];

        $formElements[] = ['type' => 'Label', 'label' => 'to the base-URL \'/hook/NetatmoSecurity/event\' is appended'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'webhook_baseurl', 'caption' => 'Base-URL'];
        $instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
        $url = CC_GetUrl($instID);
        if ($url != '') {
            $formElements[] = ['type' => 'Label', 'label' => ' ... if not given, the Connect-URL is used'];
        }

        $formElements[] = ['type' => 'Label', 'label' => 'Update data every X minutes'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'UpdateDataInterval', 'caption' => 'Minutes'];

        $formActions = [];
        $formActions[] = ['type' => 'Button', 'label' => 'Update data', 'onClick' => 'NetatmoSecurityIO_UpdateData($id);'];
        $formActions[] = ['type' => 'Label', 'label' => '____________________________________________________________________________________________________'];
        $formActions[] = ['type' => 'Button', 'label' => 'Module description', 'onClick' => 'echo \'https://github.com/demel42/IPSymconNetatmoSecurity/blob/master/README.md\';'];

        $formStatus = $this->GetFormStatus();

        return json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
    }

    protected function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('UpdateDataInterval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->SetTimerInterval('UpdateData', $msec);
    }

    protected function SendData($data, $source)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);
        $this->SendDataToChildren(json_encode(['DataID' => '{5F947426-53FB-4DD9-A725-F95590CBD97C}', 'Source' => $source, 'Buffer' => $data]));
    }

    public function ForwardData($data)
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $jdata = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($jdata, true), 0);

        $ret = '';
        if (isset($jdata['Function'])) {
            switch ($jdata['Function']) {
                case 'LastData':
                    $ret = $this->GetMultiBuffer('LastData');
                    break;
                case 'CmdUrl':
                    $ret = $this->SendCommand($jdata['Url']);
                    $this->SetTimerInterval('UpdateData', 1000);
                    break;
                default:
                    $this->SendDebug(__FUNCTION__, 'unknown function "' . $jdata['Function'] . '"', 0);
                    break;
                }
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown message-structure', 0);
        }

        $this->SendDebug(__FUNCTION__, 'ret=' . print_r($ret, true), 0);
        return $ret;
    }

    private function GetToken()
    {
        $url = 'https://api.netatmo.net/oauth2/token';

        $user = $this->ReadPropertyString('Netatmo_User');
        $password = $this->ReadPropertyString('Netatmo_Password');
        $client = $this->ReadPropertyString('Netatmo_Client');
        $secret = $this->ReadPropertyString('Netatmo_Secret');

        $dtoken = $this->GetBuffer('Token');
        $jtoken = json_decode($dtoken, true);
        $token = isset($jtoken['token']) ? $jtoken['token'] : '';
        $token_expiration = isset($jtoken['token_expiration']) ? $jtoken['token_expiration'] : 0;

        if ($token_expiration < time()) {
            $postdata = [
                'grant_type'    => 'password',
                'client_id'     => $client,
                'client_secret' => $secret,
                'username'      => $user,
                'password'      => $password,
                'scope'         => 'read_presence access_presence read_camera access_camera write_camera read_smokedetector'
            ];

            $token = '';
            $token_expiration = 0;

            $data = '';
            $err = '';
            $statuscode = $this->do_HttpRequest($url, '', $postdata, 'POST', $data, $err);
            if ($statuscode == 0) {
                $params = json_decode($data, true);
                if ($params['access_token'] == '') {
                    $statuscode = IS_INVALIDDATA;
                    $err = "no 'access_token' in response";
                } else {
                    $token = $params['access_token'];
                    $expires_in = $params['expires_in'];
                    $token_expiration = time() + $expires_in - 60;
                }
            }

            if ($statuscode) {
                $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->SetStatus($statuscode);
                $this->SetMultiBuffer('LastData', '');
                return false;
            }

            $this->SendDebug(__FUNCTION__, 'token=' . $token . ', expiration=' . $token_expiration, 0);

            $jtoken = [
                    'token'            => $token,
                    'token_expiration' => $token_expiration
                ];
            $this->SetBuffer('Token', json_encode($jtoken));

            $this->SetStatus(IS_ACTIVE);
        }
        return $token;
    }

    public function UpdateData()
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $token = $this->GetToken();
        if ($token == false) {
            return;
        }

        // Anfrage mit Token
        $url = 'https://api.netatmo.net/api/gethomedata';
        $url .= '?access_token=' . $token;

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
        if ($statuscode == 0) {
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            $status = $jdata['status'];
            if ($status != 'ok') {
                $err = "got status \"$status\"";
                $statuscode = IS_INVALIDDATA;
            } else {
                $empty = true;
                if (isset($jdata['body']['homes'])) {
                    $homes = $jdata['body']['homes'];
                    $this->SendDebug(__FUNCTION__, 'homes=' . print_r($homes, true), 0);
                    foreach ($homes as $home) {
                        if (isset($home['cameras'])) {
                            $cameras = $home['cameras'];
                            if ($cameras != '' && count($cameras)) {
                                $empty = false;
                            }
                        }
                        if (isset($home['smokedetectors'])) {
                            $smokedetectors = $home['smokedetectors'];
                            if ($smokedetectors != '' && count($smokedetectors)) {
                                $empty = false;
                            }
                        }
                    }
                }
                if ($empty) {
                    $err = 'data contains no cameras or smokedetectors';
                    $statuscode = IS_NOPRODUCT;
                }
            }
        } elseif (statuscode == IS_FORBIDDEN) {
            $this->SetBuffer('Token', '');
        }

        if ($statuscode) {
            $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->SetStatus($statuscode);
            $this->SetMultiBuffer('LastData', '');
            return false;
        }

        $this->SendData($data, 'QUERY');
        $this->SetMultiBuffer('LastData', $data);

        $this->SetStatus(IS_ACTIVE);

        $this->SetUpdateInterval();
    }

    protected function ProcessHookData()
    {
        $this->SendDebug(__FUNCTION__, '_SERVER=' . print_r($_SERVER, true), 0);

        $root = realpath(__DIR__);
        $uri = $_SERVER['REQUEST_URI'];
        if (substr($uri, -1) == '/') {
            http_response_code(404);
            die('File not found!');
        }
        $basename = substr($uri, strlen('/hook/NetatmoSecurity/'));
        if ($basename == 'event') {
            $data = file_get_contents('php://input');
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            if ($jdata == '') {
                echo 'malformed data: ' . $data;
                $this->SendDebug(__FUNCTION__, 'malformed data: ' . $data, 0);
                return;
            }
            http_response_code(200);
            $this->SendData($data, 'EVENT');
            $this->SetTimerInterval('UpdateData', 5000);
            return;
        }
        $path = realpath($root . '/' . $basename);
        if ($path === false) {
            http_response_code(404);
            die('File not found!');
        }
        if (substr($path, 0, strlen($root)) != $root) {
            http_response_code(403);
            die('Security issue. Cannot leave root folder!');
        }
        header('Content-Type: ' . $this->GetMimeType(pathinfo($path, PATHINFO_EXTENSION)));
        readfile($path);
    }

    public function AddWebhook()
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $register_webhook = $this->ReadPropertyBoolean('register_webhook');
        if (!$register_webhook) {
            $this->SendDebug(__FUNCTION__, 'don\'t register webhook', 0);
            return;
        }

        $token = $this->GetToken();
        if ($token == false) {
            return;
        }

        $url = 'https://api.netatmo.net/api/addwebhook';
        $url .= '?access_token=' . $token;

        $webhook_baseurl = $this->ReadPropertyString('webhook_baseurl');
        if ($webhook_baseurl == '') {
            $instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
            $webhook_baseurl = CC_GetUrl($instID);
            if ($url == '') {
                $this->SendDebug(__FUNCTION__, 'webhook missing', 0);
                return;
            }
        }
        $webhook_baseurl .= '/hook/NetatmoSecurity/event';
        $url .= '&url=' . rawurlencode($webhook_baseurl);

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
        if ($statuscode == 0) {
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            $status = $jdata['status'];
            if ($status != 'ok') {
                $err = "got status \"$status\"";
                $statuscode = IS_INVALIDDATA;
            }
        }
        if ($statuscode) {
            $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->SetStatus($statuscode);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function DropWebhook()
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $token = $this->GetToken();
        if ($token == false) {
            return;
        }

        $url = 'https://api.netatmo.net/api/dropwebhook';
        $url .= '?access_token=' . $token;
        $url .= '&app_types=app_security';

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
        if ($statuscode == 0) {
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            $status = $jdata['status'];
            if ($status != 'ok') {
                $err = "got status \"$status\"";
                $statuscode = IS_INVALIDDATA;
            }
        }
        if ($statuscode) {
            $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->SetStatus($statuscode);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function SendCommand(string $url)
    {
        $inst = IPS_GetInstance($this->InstanceID);
        if ($inst['InstanceStatus'] == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return false;
        }

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
        $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);
        if ($statuscode == 0) {
            $jdata = json_decode($data, true);
            if (isset($jdata['error']['messages'])) {
                $status = 'fail';
                $msg = $jdata['error']['messages'];
            } else {
                $status = 'ok';
                $msg = '';
            }
        } else {
            $status = 'fail';
            $msg = 'no data';
        }

        $ret = json_encode(['status' => $status, 'msg' => $msg]);
        $this->SendDebug(__FUNCTION__, 'ret=' . print_r($ret, true), 0);
        return $ret;
    }
}
