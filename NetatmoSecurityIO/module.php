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

        $this->RegisterPropertyInteger('sync_event_count', '30');

        $this->RegisterTimer('UpdateData', 0, 'NetatmoSecurity_UpdateData(' . $this->InstanceID . ');');
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

        if (IPS_GetKernelRunlevel() == KR_READY) {
            if ($register_webhook) {
                if ($webhook_baseurl == '') {
                    $instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
                    $webhook_baseurl = CC_GetUrl($instID);
                    if ($webhook_baseurl == '') {
                        $this->SetStatus(IS_NOWEBHOOK);
                        return;
                    }
                }
                if ($this->HookIsUsed('/hook/NetatmoSecurity')) {
                    $this->SetStatus(IS_USEDWEBHOOK);
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

            if (IPS_GetKernelRunlevel() == KR_READY) {
                if ($register_webhook) {
                    $this->AddWebhook();
                } else {
                    $this->DropWebhook();
                }
            }
        } else {
            $this->SetStatus(IS_INACTIVE);
        }
    }

    public function GetConfigurationForm()
    {
        $formElements = [];
        $formElements[] = ['type' => 'CheckBox', 'name' => 'module_disable', 'caption' => 'Instance is disabled'];

        $formElements[] = ['type' => 'Label', 'caption' => 'Netatmo Access-Details'];
        $formElements[] = ['type' => 'Label', 'caption' => 'Netatmo-Account from https://my.netatmo.com'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_User', 'caption' => 'Username'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_Password', 'caption' => 'Password'];
        $formElements[] = ['type' => 'Label', 'caption' => 'Netatmo-Connect from https://dev.netatmo.com'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_Client', 'caption' => 'Client ID'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'Netatmo_Secret', 'caption' => 'Client Secret'];

        $formElements[] = ['type' => 'Label', 'caption' => 'Ignore HTTP-Error X times'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'ignore_http_error', 'caption' => 'Count'];

        $formElements[] = ['type' => 'Label', 'caption' => 'Number of events retrieved during an update'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'sync_event_count', 'caption' => 'Count'];

        $formElements[] = ['type' => 'Label', 'caption' => 'Webhook for receive events from Netatmo (must be reachable from internet)'];
        $formElements[] = ['type' => 'CheckBox', 'name' => 'register_webhook', 'caption' => 'Register Webhook'];

        $formElements[] = ['type' => 'Label', 'caption' => 'to the base-URL \'/hook/NetatmoSecurity/event\' is appended'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'webhook_baseurl', 'caption' => 'Base-URL'];
        $instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
        $url = CC_GetUrl($instID);
        if ($url != '') {
            $formElements[] = ['type' => 'Label', 'caption' => ' ... if not given, the Connect-URL is used'];
        }

        $formElements[] = ['type' => 'Label', 'caption' => 'Update data every X minutes'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'UpdateDataInterval', 'caption' => 'Minutes'];

        $formActions = [];
        $formActions[] = ['type' => 'Button', 'label' => 'Update data', 'onClick' => 'NetatmoSecurity_UpdateData($id);'];
        $formActions[] = ['type' => 'Button', 'label' => 'Register Webhook', 'onClick' => 'NetatmoSecurity_AddWebhook($id);'];
        $formActions[] = ['type' => 'Label', 'caption' => '____________________________________________________________________________________________________'];
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
                    $url = $jdata['Url'];
                    if (isset($jdata['NeedToken']) && $jdata['NeedToken']) {
                        $url .= '&access_token=' . $this->GetToken();
                    }
                    $ret = $this->SendCommand($url);
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

    private function do_HttpRequest($url, $header, $postdata, $mode, &$data, &$err)
    {
        $this->SendDebug(__FUNCTION__, 'http-' . $mode . ': url=' . $url, 0);
        $time_start = microtime(true);

        if ($header != '') {
            $this->SendDebug(__FUNCTION__, '    header=' . print_r($header, true), 0);
        }
        if ($postdata != '') {
            $this->SendDebug(__FUNCTION__, '    postdata=' . print_r($postdata, true), 0);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($header) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }
        switch ($mode) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $mode);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $mode);
                break;
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
            } elseif ($httpcode == 403) {
                $statuscode = IS_FORBIDDEN;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode == 409) {
                $data = $cdata;
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

        $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
        $this->SendDebug(__FUNCTION__, '    data=' . $data, 0);
        return $statuscode;
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

        $sync_event_count = $this->ReadPropertyInteger('sync_event_count');

        // Anfrage mit Token
        $url = 'https://api.netatmo.net/api/gethomedata';
        $url .= '?access_token=' . $token;
        $url .= '&size=' . $sync_event_count;

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
        } elseif ($statuscode == IS_FORBIDDEN) {
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

		$xtra_data = '{"body":{"homes":[{"id":"5a3517db8af1054ff88b47a1","name":"Zuhause","persons":[{"id":"11dc7033-0b26-4a92-905d-9da2633b124a","last_seen":1562914184,"out_of_sight":false,"face":{"id":"5a3519b6316480cb2c8b4789","key":"4d88dc2b69c96255e8e5b603c3d88eff2d000e71013e16fadc6f988d47f8e482","version":2,"url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5a3519b6316480cb2c8b47894d88dc2b69c96255e8e5b603c3d88eff2d000e71013e16fadc6f988d47f8e482"},"pseudo":"Christopher"},{"id":"42741aa7-71e4-4533-a826-164d47fcb61e","last_seen":1556814750,"out_of_sight":true,"face":{"id":"5a7c47288e17829d568b47b5","key":"b950529eb443b270d8ddfdb93f20d3c1d749232517ef3a8180975264299916d2","version":1,"url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5a7c47288e17829d568b47b5b950529eb443b270d8ddfdb93f20d3c1d749232517ef3a8180975264299916d2"},"pseudo":"Eva-Maria"},{"id":"64d8df12-c7f4-4eec-a1b0-e15b10f0e7ac","last_seen":1562443241,"out_of_sight":true,"face":{"id":"5a7b3dd92b2b46f6428b47c0","key":"626eb98badf089ad9ebea0eefde11cc1dfc800865f78be526a8dce523bbb7245","version":1,"url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5a7b3dd92b2b46f6428b47c0626eb98badf089ad9ebea0eefde11cc1dfc800865f78be526a8dce523bbb7245"},"pseudo":"Mike"},{"id":"8b079adf-5e45-443d-ab01-8fa4e5c0290d","last_seen":1554059295,"out_of_sight":true,"face":{"id":"5a8085e0ac34a5c5258b4a52","key":"aa1a53a27a182ddf6418d6200e0d832af6bcec64a76d0274e316467406a72fba","version":1,"url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5a8085e0ac34a5c5258b4a52aa1a53a27a182ddf6418d6200e0d832af6bcec64a76d0274e316467406a72fba"},"pseudo":"Nadja"},{"id":"e81d0c84-9905-4d15-a111-208c7639b917","last_seen":1518951173,"out_of_sight":true,"face":{"id":"5a895b018e17828cc58b46f9","key":"58822829293a0711a21811172aa009eaf68320a467cf3bc95ced242db5310d6b","version":1,"url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5a895b018e17828cc58b46f958822829293a0711a21811172aa009eaf68320a467cf3bc95ced242db5310d6b"},"pseudo":"Raphael"},{"id":"e9a1e968-9f48-4302-8690-90a8c884622e","last_seen":1518375132,"out_of_sight":true,"face":{"id":"5a8090308c04c4a88e8b4722","key":"7966cf83fcc2c2a5451f345834e1b734e087c31768200cb4d395c72c39584cbd","version":1,"url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5a8090308c04c4a88e8b47227966cf83fcc2c2a5451f345834e1b734e087c31768200cb4d395c72c39584cbd"},"pseudo":"Mara"},{"id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","last_seen":1562906368,"out_of_sight":false,"face":{"id":"5c87feb1c9c7c02e818d03bd","version":1,"key":"11c4c19bc2ccc162833b5b304c58ff0cd1b994a14c739a99b1592022458f2fc3","url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5c87feb1c9c7c02e818d03bd11c4c19bc2ccc162833b5b304c58ff0cd1b994a14c739a99b1592022458f2fc3"},"pseudo":"Pauline"},{"id":"4d7ab628-e0d9-430a-b2a8-047852492355","last_seen":1556644814,"out_of_sight":true,"face":{"id":"5c9ca8626b5cc291e68e8f0c","version":1,"key":"072f249c9aed61d4f59186f702072a54b997087ef2da8630724140c39700a41c","url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5c9ca8626b5cc291e68e8f0c072f249c9aed61d4f59186f702072a54b997087ef2da8630724140c39700a41c"},"pseudo":"Dennis"},{"id":"5d87994c-ed54-4be3-bf37-c7bb74e8070e","last_seen":1562026358,"out_of_sight":true,"face":{"id":"5d1aa17aa11ec57f6468cb13","version":1,"key":"2742bd52ffab6cdb1d9198749f64cd15d03188519d2335acf393679a331a1cc6","url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5d1aa17aa11ec57f6468cb132742bd52ffab6cdb1d9198749f64cd15d03188519d2335acf393679a331a1cc6"}},{"id":"7711838f-5bd3-4871-a9a7-9692170344b2","last_seen":1562862702,"out_of_sight":true,"face":{"id":"5d27647397d0263cff7c558c","version":1,"key":"4ebf5d76d511daada9e358b3ba61d1dd0a8b87a30eec85b351ad181e69790deb","url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5d27647397d0263cff7c558c4ebf5d76d511daada9e358b3ba61d1dd0a8b87a30eec85b351ad181e69790deb"},"pseudo":"Vermieter"}],"place":{"city":"Osnabr\u00fcck","country":"DE","timezone":"Europe\/Berlin"},"cameras":[{"id":"70:ee:50:21:b2:f9","type":"NACamera","status":"on","vpn_url":"https:\/\/prodvpn-eu-3.netatmo.net\/restricted\/10.255.86.65\/ec3d6f9a32080d1ff20e12fa0ff3b470\/MTU2MjkzMjgwMDqsV5RWpIZR0NdpCf3uDKkc6CK_Fw,,","is_local":true,"sd_status":"on","alim_status":"on","name":"Flur","last_setup":1513428955}],"smokedetectors":[],"events":[{"id":"5d282d8b41a113d2f8673b36","type":"person","time":1562914184,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":true,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d280f0252aa5f8dff39266c","type":"person","time":1562906368,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d2808abe0c2b1f8fb0cd1a8","type":"person","time":1562904745,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":true,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d27804d273f77e9b63153ab","type":"person","time":1562869835,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d27784360dd8cb91125c6d0","type":"person","time":1562867767,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d27709174bfbd37ee80e23d","type":"person","time":1562865806,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d276f18c5bdbd8725422c13","type":"person","time":1562865430,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d276efe815e2646cd3dacd0","type":"person","time":1562865404,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d276ca46df87f1b313f57cf","type":"person","time":1562864802,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d276c7741a113bb885dc1bb","type":"person","time":1562864742,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d276ae95c622caadb6e3a55","type":"person","time":1562864359,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d2768b44d16438dcb7a517c","type":"person","time":1562863693,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d2768b4dfd1ec908a5bb684","type":"person","time":1562863671,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d2766e4affea0a16a0bf9fe","type":"person","time":1562863330,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d27661060dd8c46e1141f3e","type":"person","time":1562863118,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d2765ba52aa5f0abf7029a9","type":"person","time":1562863013,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d2765ba95a5b64df5698f5f","type":"person","time":1562862981,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d27647397d0263cff7c558b","type":"person","time":1562862702,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"7711838f-5bd3-4871-a9a7-9692170344b2","snapshot":{"id":"5d27647397d0263cff7c558d","version":1,"key":"916db87ce6c4f0c93729a19d315f103f9e1457401cffac806d23a808abeb5b6a","url":"https:\/\/netatmocameraimage.blob.core.windows.net\/production\/5d27647397d0263cff7c558d916db87ce6c4f0c93729a19d315f103f9e1457401cffac806d23a808abeb5b6a"},"video_id":"51497905-400a-4185-b34c-e5387c156900","video_status":"available","message":"<b>Vermieter<\/b> gesehen"},{"id":"5d2763e64009033fff4445cb","type":"person","time":1562862557,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d2763c61073ae902f40dfb2","type":"person","time":1562862525,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d275552665a133dbf6f3484","type":"person","time":1562858831,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":true,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d273d0952aa5f000f6aef4d","type":"person_away","time":1562852615,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","message":"<b>Christopher<\/b> hat das Haus verlassen","sub_message":"Christopher gilt als abwesend, da das mit diesem Profil verbundene Telefon den Bereich des Hauses verlassen hat."},{"id":"5d273c2c0087bb438b5738dc","type":"person","time":1562852394,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d273b7edfd1ecb40934f866","type":"person","time":1562852220,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":true,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d2735b8a11ec500165cdda8","type":"person_away","time":1562849931,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","message":"<b>Christopher<\/b> hat das Haus verlassen","sub_message":"Christopher gilt als abwesend, da das mit diesem Profil verbundene Telefon den Bereich des Hauses verlassen hat."},{"id":"5d273139f566fac72d76eb93","type":"person","time":1562849591,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"},{"id":"5d272e21a11ec57af57252e7","type":"person","time":1562848798,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d272ddc8b2345fb251d2b4d","type":"person","time":1562848729,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d272d6134ff8c014444295f","type":"person","time":1562848606,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"8b8cc378-e8f6-4497-892c-d66c14024b8a","video_status":"deleted","is_arrival":false,"message":"<b>Pauline<\/b> gesehen"},{"id":"5d2722fea11ec552f64898d3","type":"person","time":1562845946,"camera_id":"70:ee:50:21:b2:f9","device_id":"70:ee:50:21:b2:f9","person_id":"11dc7033-0b26-4a92-905d-9da2633b124a","video_status":"deleted","is_arrival":false,"message":"<b>Christopher<\/b> gesehen"}]}],"user":{"reg_locale":"de-DE","lang":"de-DE","country":"DE_DE","mail":"christopher.wansing@gmail.com"},"global_info":{"show_tags":true}},"status":"ok","time_exec":0.16000080108642578,"time_server":1562923759}';
        $this->SendData($xtra_data, 'QUERY');

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
            if ($webhook_baseurl == '') {
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
