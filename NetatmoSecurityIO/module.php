<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen

class NetatmoSecurityIO extends IPSModule
{
    use NetatmoSecurityCommon;

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

        $this->RegisterPropertyBoolean('with_webhook', false);

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
        }
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
        if ($uri == '/hook/NetatmoSecurity') {
            $this->SendDebug(__FUNCTION__, '_GET=' . print_r($_GET, true), 0);
            $this->SendDebug(__FUNCTION__, '_PUT=' . print_r($_PUT, true), 0);
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

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->SetTimerInterval('UpdateData', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $netatmo_user = $this->ReadPropertyString('Netatmo_User');
        $netatmo_password = $this->ReadPropertyString('Netatmo_Password');
        $netatmo_client = $this->ReadPropertyString('Netatmo_Client');
        $netatmo_secret = $this->ReadPropertyString('Netatmo_Secret');

        if ($netatmo_user != '' && $netatmo_password != '' && $netatmo_client != '' && $netatmo_secret != '') {
            $this->SetUpdateInterval();
            // Inspired by module SymconTest/HookServe
            // We need to call the RegisterHook function on Kernel READY
            if (IPS_GetKernelRunlevel() == KR_READY) {
                $this->UpdateData();
            }
            $this->SetStatus(IS_ACTIVE);
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
        $formElements[] = ['type' => 'Label', 'label' => ''];
        $formElements[] = ['type' => 'Label', 'label' => 'Update weatherdata every X minutes'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'UpdateDataInterval', 'caption' => 'Minutes'];

        $formActions = [];
        $formActions[] = ['type' => 'Button', 'label' => 'Update weatherdata', 'onClick' => 'NetatmoSecurityIO_UpdateData($id);'];
        $formActions[] = ['type' => 'Label', 'label' => '____________________________________________________________________________________________________'];
        $formActions[] = ['type' => 'Button', 'label' => 'Module description', 'onClick' => 'echo \'https://github.com/demel42/IPSymconNetatmoSecurity/blob/master/README.md\';'];

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

    protected function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('UpdateDataInterval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->SetTimerInterval('UpdateData', $msec);
    }

    protected function SendData($data)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);
        $this->SendDataToChildren(json_encode(['DataID' => '{5F947426-53FB-4DD9-A725-F95590CBD97C}', 'Buffer' => $data]));
    }

    public function ForwardData($data)
    {
        $last_data = $this->GetBuffer('LastData');
        $this->SendDebug(__FUNCTION__, 'last_data=' . print_r($last_data, true), 0);
        return $last_data;
    }

    public function UpdateData()
    {
        $inst = IPS_GetInstance($this->InstanceID);
        if ($inst['InstanceStatus'] == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $netatmo_auth_url = 'https://api.netatmo.net/oauth2/token';
        $netatmo_data_url = 'https://api.netatmo.net/api/gethomedata';

        $netatmo_user = $this->ReadPropertyString('Netatmo_User');
        $netatmo_password = $this->ReadPropertyString('Netatmo_Password');
        $netatmo_client = $this->ReadPropertyString('Netatmo_Client');
        $netatmo_secret = $this->ReadPropertyString('Netatmo_Secret');

        $dtoken = $this->GetBuffer('Token');
        $jtoken = json_decode($dtoken, true);
        $token = isset($jtoken['token']) ? $jtoken['token'] : '';
        $token_expiration = isset($jtoken['token_expiration']) ? $jtoken['token_expiration'] : 0;

        if ($token_expiration < time()) {
            $postdata = [
                'grant_type'    => 'password',
                'client_id'     => $netatmo_client,
                'client_secret' => $netatmo_secret,
                'username'      => $netatmo_user,
                'password'      => $netatmo_password,
                'scope'         => 'read_presence'
            ];

            $this->SendDebug(__FUNCTION__, "netatmo-auth-url=$netatmo_auth_url, postdata=" . print_r($postdata, true), 0);

            $token = '';
            $token_expiration = 0;

            $do_abort = false;
            $response = $this->do_HttpRequest($netatmo_auth_url, $postdata);
            if ($response != '') {
                $params = json_decode($response, true);
                if ($params['access_token'] == '') {
                    $statuscode = IS_INVALIDDATA;
                    $err = "no 'access_token' in response";
                    $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                    $this->SendDebug(__FUNCTION__, $err, 0);
                    $this->SetStatus($statuscode);
                    $do_abort = true;
                } else {
                    $token = $params['access_token'];
                    $expires_in = $params['expires_in'];
                    $token_expiration = time() + $expires_in - 60;
                }
            } else {
                $do_abort = true;
            }

            $this->SendDebug(__FUNCTION__, 'token=' . $token . ', expiration=' . $token_expiration, 0);

            $jtoken = [
                    'token'            => $token,
                    'token_expiration' => $token_expiration
                ];
            $this->SetBuffer('Token', json_encode($jtoken));

            if ($do_abort) {
                // $this->SendData('');
                $this->SetBuffer('LastData', '');
                return -1;
            }
        }

        // Anfrage mit Token
        $netatmo_data_url .= '?access_token=' . $token;

        $do_abort = false;
        $data = $this->do_HttpRequest($netatmo_data_url);
        if ($data != '') {
            $err = '';
            $statuscode = 0;
            $netatmo = json_decode($data, true);
            $status = $netatmo['status'];
            if ($status != 'ok') {
                $err = "got status \"$status\"";
                $statuscode = IS_INVALIDDATA;
            } else {
                $devices = $netatmo['body']['devices'];
                if (!count($devices)) {
                    $err = 'data contains no station';
                    $statuscode = IS_NOSTATION;
                }
            }
            if ($statuscode) {
                $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->SetStatus($statuscode);
                $do_abort = true;
            }
        } else {
            $do_abort = true;
        }

        if ($do_abort) {
            // $this->SendData('');
            $this->SetBuffer('LastData', '');
            return -1;
        }

        $this->SetStatus(IS_ACTIVE);

        $this->SendData($data);
        $this->SetBuffer('LastData', $data);
    }

    private function do_HttpRequest($url, $postdata = '')
    {
        $inst = IPS_GetInstance($this->InstanceID);
        if ($inst['InstanceStatus'] == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $ignore_http_error = $this->ReadPropertyInteger('ignore_http_error');

        $this->SendDebug(__FUNCTION__, 'http-' . ($postdata != '' ? 'post' : 'get') . ': url=' . $url, 0);
        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($postdata != '') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $cdata = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, '    cdata=' . $cdata, 0);

        $statuscode = 0;
        $err = '';
        $data = '';
        if ($cerrno) {
            $statuscode = IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } elseif ($httpcode != 200) {
            if ($httpcode == 401) {
                $statuscode = IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } else {
                $statuscode = IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        } elseif ($cdata == '') {
            $statuscode = IS_NODATA;
            $err = 'no data';
        } else {
            $jdata = json_decode($cdata, true);
            if ($jdata == '') {
                $statuscode = IS_INVALIDDATA;
                $err = 'malformed response';
            } else {
                $data = $cdata;
            }
        }

        if ($statuscode) {
            $cstat = $this->GetBuffer('LastStatus');
            if ($cstat != '') {
                $jstat = json_decode($cstat, true);
            } else {
                $jstat = [];
            }
            $jstat[] = ['statuscode' => $statuscode, 'err' => $err, 'tstamp' => time()];
            $n_stat = count($jstat);
            $cstat = json_encode($jstat);
            $this->LogMessage('url=' . $url . ' => statuscode=' . $statuscode . ', err=' . $err . ', status #' . $n_stat, KL_WARNING);

            if ($n_stat >= $ignore_http_error) {
                $this->SetStatus($statuscode);
                $cstat = '';
            }
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err . ', status #' . $n_stat, 0);
        } else {
            $cstat = '';
        }
        $this->SetBuffer('LastStatus', $cstat);

        return $data;
    }
}
