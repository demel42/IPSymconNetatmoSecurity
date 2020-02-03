<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/library.php'; // modul-bezogene Funktionen

class NetatmoSecurityIO extends IPSModule
{
    use NetatmoSecurityCommon;
    use NetatmoSecurityLibrary;

    private $oauthIdentifer = 'netatmo';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('Netatmo_User', '');
        $this->RegisterPropertyString('Netatmo_Password', '');
        $this->RegisterPropertyString('Netatmo_Client', '');
        $this->RegisterPropertyString('Netatmo_Secret', '');

        $this->RegisterPropertyInteger('UpdateDataInterval', '5');

        $this->RegisterPropertyBoolean('register_webhook', false);
        $this->RegisterPropertyString('webhook_baseurl', '');

        $this->RegisterPropertyInteger('sync_event_count', '30');

        $this->RegisterPropertyInteger('OAuth_Type', CONNECTION_UNDEFINED);

        $this->RegisterAttributeString('ApiRefreshToken', '');
        $this->RegisterAttributeString('AppRefreshToken', '');

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
            $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
            if ($oauth_type == CONNECTION_OAUTH) {
                $this->RegisterOAuth($this->oauthIdentifer);
            }
            $this->RegisterHook('/hook/NetatmoSecurity');
            $this->UpdateData();
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

        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        switch ($oauth_type) {
            case CONNECTION_DEVELOPER:
                $user = $this->ReadPropertyString('Netatmo_User');
                $password = $this->ReadPropertyString('Netatmo_Password');
                $client = $this->ReadPropertyString('Netatmo_Client');
                $secret = $this->ReadPropertyString('Netatmo_Secret');
                if ($user == '' || $password == '' || $client == '' || $secret == '') {
                    $this->SetStatus(IS_INACTIVE);
                    return;
                }
                $this->SetStatus(IS_ACTIVE);
                break;
            case CONNECTION_OAUTH:
                if ($this->GetConnectUrl() == false) {
                    $this->SetStatus(IS_NOSYMCONCONNECT);
                    return;
                }
                $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
                if ($refresh_token == '') {
                    $this->SetStatus(IS_NOLOGIN);
                } else {
                    $this->SetStatus(IS_ACTIVE);
                }
                break;
            default:
                break;
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            if ($oauth_type == CONNECTION_OAUTH) {
                $this->RegisterOAuth($this->oauthIdentifer);
            }
            $register_webhook = $this->ReadPropertyBoolean('register_webhook');
            if ($register_webhook) {
                $webhook_baseurl = $this->ReadPropertyString('webhook_baseurl');
                if ($webhook_baseurl == '') {
                    $webhook_baseurl = $this->GetConnectUrl();
                    if ($webhook_baseurl == '') {
                        $this->SetStatus(IS_NOWEBHOOK);
                        return;
                    }
                }
                if ($this->HookIsUsed('/hook/NetatmoSecurity')) {
                    $this->SetStatus(IS_USEDWEBHOOK);
                    return;
                }
            } else {
                $this->DropWebhook();
            }
            $this->SetTimerInterval('UpdateData', 1000);
        }
    }

    private function RegisterOAuth($WebOAuth)
    {
        $ids = IPS_GetInstanceListByModuleID('{F99BF07D-CECA-438B-A497-E4B55F139D37}');
        if (count($ids) > 0) {
            $clientIDs = json_decode(IPS_GetProperty($ids[0], 'ClientIDs'), true);
            $found = false;
            foreach ($clientIDs as $index => $clientID) {
                if ($clientID['ClientID'] == $WebOAuth) {
                    if ($clientID['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $clientIDs[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $clientIDs[] = ['ClientID' => $WebOAuth, 'TargetID' => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], 'ClientIDs', json_encode($clientIDs));
            IPS_ApplyChanges($ids[0]);
        }
    }

    public function Login()
    {
        $url = 'https://oauth.ipmagic.de/authorize/' . $this->oauthIdentifer . '?username=' . urlencode(IPS_GetLicensee());
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    protected function Call4AccessToken($content)
    {
        $url = 'https://oauth.ipmagic.de/access_token/' . $this->oauthIdentifer;
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '    content=' . print_r($content, true), 0);

        $statuscode = 0;
        $err = '';
        $jdata = false;

        $time_start = microtime(true);
        $options = [
            'http' => [
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($content)
            ]
        ];
        $context = stream_context_create($options);
        $cdata = @file_get_contents($url, false, $context);
        $duration = round(microtime(true) - $time_start, 2);
        if (preg_match('/HTTP\/[0-9\.]+\s+([0-9]*)/', $http_response_header[0], $r)) {
            $httpcode = $r[1];
        } else {
            $this->SendDebug(__FUNCTION__, 'http_response_header=' . print_r($http_response_header, true), 0);
            $httpcode = 0;
        }
        $this->SendDebug(__FUNCTION__, ' => httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, '    cdata=' . $cdata, 0);

        if ($httpcode != 200) {
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
                if (!isset($jdata['refresh_token'])) {
                    $statuscode = IS_INVALIDDATA;
                    $err = 'malformed response';
                }
            }
        }
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->SetStatus($statuscode);
            return false;
        }
        return $jdata;
    }

    private function FetchRefreshToken($code)
    {
        $this->SendDebug(__FUNCTION__, 'code=' . $code, 0);
        $jdata = $this->Call4AccessToken(['code' => $code]);
        if ($jdata == false) {
            $this->SendDebug(__FUNCTION__, 'got no token', 0);
            $this->SetBuffer('ApiAccessToken', '');
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
        $access_token = $jdata['access_token'];
        $expiration = time() + $jdata['expires_in'];
        $refresh_token = $jdata['refresh_token'];
        $this->FetchAccessToken($access_token, $expiration);
        return $refresh_token;
    }

    private function FetchAccessToken($access_token = '', $expiration = 0)
    {
        if ($access_token == '' && $expiration == 0) {
            $data = $this->GetBuffer('ApiAccessToken');
            if ($data != '') {
                $jdata = json_decode($data, true);
                if (time() < $jdata['expiration']) {
                    $this->SendDebug(__FUNCTION__, 'access_token=' . $jdata['access_token'] . ', valid until ' . date('d.m.y H:i:s', $jdata['expiration']), 0);
                    return $jdata['access_token'];
                } else {
                    $this->SendDebug(__FUNCTION__, 'access_token expired', 0);
                }
            } else {
                $this->SendDebug(__FUNCTION__, 'access_token not saved', 0);
            }
            $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
            $this->SendDebug(__FUNCTION__, 'refresh_token=' . print_r($refresh_token, true), 0);
            if ($refresh_token == 'False') {
                $this->SendDebug(__FUNCTION__, 'has no refresh_token', 0);
                $this->SetBuffer('ApiRefreshToken', '');
                return false;
            }
            if ($refresh_token == '') {
                $this->SendDebug(__FUNCTION__, 'has no refresh_token', 0);
                $this->SetBuffer('ApiAccessToken', '');
                return false;
            }
            $jdata = $this->Call4AccessToken(['refresh_token' => $refresh_token]);
            if ($jdata == false) {
                $this->SendDebug(__FUNCTION__, 'got no access_token', 0);
                $this->SetBuffer('ApiAccessToken', '');
                return false;
            }
            $access_token = $jdata['access_token'];
            $expiration = time() + $jdata['expires_in'];
            if (isset($jdata['refresh_token'])) {
                $refresh_token = $jdata['refresh_token'];
                $this->SendDebug(__FUNCTION__, 'new refresh_token=' . $refresh_token, 0);
                $this->WriteAttributeString('ApiRefreshToken', $refresh_token);
            }
        }
        $this->SendDebug(__FUNCTION__, 'new access_token=' . $access_token . ', valid until ' . date('d.m.y H:i:s', $expiration), 0);
        $this->SetBuffer('ApiAccessToken', json_encode(['access_token' => $access_token, 'expiration' => $expiration]));
        return $access_token;
    }

    protected function ProcessOAuthData()
    {
        if (!isset($_GET['code'])) {
            $this->SendDebug(__FUNCTION__, 'code missing, _GET=' . print_r($_GET, true), 0);
            $this->SetStatus(IS_INVALIDCONFIG);
            $this->WriteAttributeString('ApiRefreshToken', '');
            return;
        }
        $refresh_token = $this->FetchRefreshToken($_GET['code']);
        $this->SendDebug(__FUNCTION__, 'refresh_token=' . $refresh_token, 0);
        $this->WriteAttributeString('ApiRefreshToken', $refresh_token);
        if ($this->GetStatus() == IS_INVALIDCONFIG) {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    public function GetConfigurationForm()
    {
        $formElements = $this->GetFormElements();
        $formActions = $this->GetFormActions();
        $formStatus = $this->GetFormStatus();

        $form = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
        if ($form == '') {
            $this->SendDebug(__FUNCTION__, 'json_error=' . json_last_error_msg(), 0);
            $this->SendDebug(__FUNCTION__, '=> formElements=' . print_r($formElements, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formActions=' . print_r($formActions, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formStatus=' . print_r($formStatus, true), 0);
        }
        return $form;
    }

    protected function GetFormElements()
    {
        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');

        $formElements = [];
        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Instance is disabled'
        ];

        if ($oauth_type == CONNECTION_OAUTH) {
            $instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
            if (IPS_GetInstance($instID)['InstanceStatus'] != IS_ACTIVE) {
                $msg = 'Error: Symcon Connect is not active!';
            } else {
                $msg = 'Status: Symcon Connect is OK!';
            }
            $formElements[] = [
                'type'    => 'Label',
                'caption' => $msg
            ];
        }

        $formElements[] = [
            'type'    => 'Select',
            'name'    => 'OAuth_Type',
            'caption' => 'Connection Type',
            'options' => [
                [
                    'caption' => 'Please select a connection type',
                    'value'   => CONNECTION_UNDEFINED
                ],
                [
                    'caption' => 'Netatmo via IP-Symcon Connect',
                    'value'   => CONNECTION_OAUTH
                ],
                [
                    'caption' => 'Netatmo Developer Key',
                    'value'   => CONNECTION_DEVELOPER
                ]
            ]
        ];

        switch ($oauth_type) {
            case CONNECTION_OAUTH:
                $items = [];
                $items[] = [
                    'type'    => 'Label',
                    'caption' => 'Push "Login at Netatmo" in the action part of this configuration form.'
                ];
                $items[] = [
                    'type'    => 'Label',
                    'caption' => 'At the webpage from Netatmo log in with your Netatmo username and password.'
                ];
                $items[] = [
                    'type'    => 'Label',
                    'caption' => 'If the connection to IP-Symcon was successfull you get the message: "Netatmo successfully connected!". Close the browser window.'
                ];
                $items[] = [
                    'type'    => 'Label',
                    'caption' => 'Return to this configuration form.'
                ];
                $formElements[] = [
                    'type'    => 'ExpansionPanel',
                    'items'   => $items,
                    'caption' => 'Netatmo Login'
                ];
                break;
            case CONNECTION_DEVELOPER:
                $items = [];
                $items[] = [
                    'type'    => 'Label',
                    'caption' => 'Netatmo-Account from https://my.netatmo.com'
                ];
                $items[] = [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Netatmo_User',
                    'caption' => 'Username'
                ];
                $items[] = [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Netatmo_Password',
                    'caption' => 'Password'
                ];
                $items[] = [
                    'type'    => 'Label',
                    'caption' => 'Netatmo-Connect from https://dev.netatmo.com'
                ];
                $items[] = [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Netatmo_Client',
                    'caption' => 'Client ID'
                ];
                $items[] = [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Netatmo_Secret',
                    'caption' => 'Client Secret'
                ];
                $formElements[] = [
                    'type'    => 'ExpansionPanel',
                    'items'   => $items,
                    'caption' => 'Netatmo Access-Details'
                ];
                break;
            default:
                break;
        }

        $items = [];
        $items[] = [
            'type'    => 'Label',
            'caption' => 'Number of events retrieved during an update'
        ];
        $items[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'sync_event_count',
            'caption' => 'Count'
        ];
        $items[] = [
            'type'    => 'Label',
            'caption' => 'Update data every X minutes'
        ];
        $items[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'UpdateDataInterval',
            'caption' => 'Minutes'
        ];
        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => $items,
            'caption' => 'Call settings'
        ];

        $items = [];
        $items[] = [
            'type'    => 'Label',
            'caption' => 'Webhook for receive notifications from Netatmo (must be reachable from internet)'
        ];
        $items[] = [
            'type'    => 'CheckBox',
            'name'    => 'register_webhook',
            'caption' => 'Register Webhook'
        ];
        $items[] = [
            'type'    => 'Label',
            'caption' => 'to the base-URL \'/hook/NetatmoSecurity/event\' is appended'
        ];
        $items[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'webhook_baseurl',
            'caption' => 'Base-URL'
        ];
        if ($this->GetConnectUrl() != false) {
            $items[] = [
                'type'    => 'Label',
                'caption' => ' ... if not given, the Connect-URL is used'
            ];
        }
        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => $items,
            'caption' => 'Notification settings'
        ];

        return $formElements;
    }

    protected function GetFormActions()
    {
        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');

        $formActions = [];

        if ($oauth_type == CONNECTION_OAUTH) {
            $formActions[] = [
                'type'    => 'Button',
                'caption' => 'Login at Netatmo',
                'onClick' => 'echo NetatmoSecurity_Login($id);'
            ];
        }

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Update data',
            'onClick' => 'NetatmoSecurity_UpdateData($id);'
        ];
        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Register Webhook',
            'onClick' => 'NetatmoSecurity_AddWebhook($id);'
        ];

        return $formActions;
    }

    protected function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('UpdateDataInterval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->SetTimerInterval('UpdateData', $msec);
        $this->SendDebug(__FUNCTION__, 'min=' . $min . ', msec=' . $msec, 0);
    }

    protected function SendData($data, $source)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);
        $this->SendDataToChildren(json_encode(['DataID' => '{5F947426-53FB-4DD9-A725-F95590CBD97C}', 'Source' => $source, 'Buffer' => $data]));
    }

    public function ForwardData($data)
    {
        if ($this->CheckStatus() == STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
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
                case 'CmdUrlGet':
                    $url = $jdata['Url'];
                    $ret = $this->SendCommand($url);
                    $this->SetTimerInterval('UpdateData', 1000);
                    break;
                case 'CmdUrlGetWithAuth':
                    $url = $jdata['Url'];
                    $url .= '&access_token=' . $this->GetApiAccessToken();
                    $ret = $this->SendCommand($url);
                    $this->SetTimerInterval('UpdateData', 1000);
                    break;
                case 'CmdUrlPostWithAuth':
                    $url = $jdata['Url'];
                    $postdata = $jdata['PostData'];
                    $ret = $this->SendCommandWithAuth($url, $postdata);
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

    private function GetApiAccessToken()
    {
        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        switch ($oauth_type) {
            case CONNECTION_OAUTH:
                $access_token = $this->FetchAccessToken();
                break;
            case CONNECTION_DEVELOPER:
                $url = 'https://api.netatmo.net/oauth2/token';

                $user = $this->ReadPropertyString('Netatmo_User');
                $password = $this->ReadPropertyString('Netatmo_Password');
                $client = $this->ReadPropertyString('Netatmo_Client');
                $secret = $this->ReadPropertyString('Netatmo_Secret');

                $jtoken = json_decode($this->GetBuffer('ApiAccessToken'), true);
                $access_token = isset($jtoken['access_token']) ? $jtoken['access_token'] : '';
                $expiration = isset($jtoken['expiration']) ? $jtoken['expiration'] : 0;

                if ($expiration < time()) {
                    $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
                    if ($refresh_token == '') {
                        $postdata = [
                            'grant_type'    => 'password',
                            'client_id'     => $client,
                            'client_secret' => $secret,
                            'username'      => $user,
                            'password'      => $password,
                            'scope'         => 'read_presence write_presence access_presence read_camera write_camera access_camera read_smokedetector'
                        ];
                    } else {
                        $postdata = [
                            'grant_type'    => 'refresh_token',
                            'client_id'     => $client,
                            'client_secret' => $secret,
                            'refresh_token' => $refresh_token
                        ];
                    }

                    $data = '';
                    $err = '';
                    $statuscode = $this->do_HttpRequest($url, '', $postdata, 'POST', $data, $err);
                    if ($statuscode == 0) {
                        $params = json_decode($data, true);
                        $this->SendDebug(__FUNCTION__, 'params=' . print_r($params, true), 0);
                        if ($params['access_token'] == '') {
                            $statuscode = IS_INVALIDDATA;
                            $err = "no 'access_token' in response";
                        }
                    }

                    if ($statuscode) {
                        $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                        $this->SendDebug(__FUNCTION__, $err, 0);
                        $this->SetStatus($statuscode);
                        $this->SetMultiBuffer('LastData', '');
                        return false;
                    }

                    $access_token = $params['access_token'];
                    $expires_in = $params['expires_in'];
                    $expiration = time() + $expires_in - 60;
                    $this->SendDebug(__FUNCTION__, 'new access_token=' . $access_token . ', valid until ' . date('d.m.y H:i:s', $expiration), 0);
                    $jtoken = [
                        'access_token' => $access_token,
                        'expiration'   => $expiration,
                    ];
                    $this->SetBuffer('ApiAccessToken', json_encode($jtoken));

                    if (isset($params['refresh_token'])) {
                        $refresh_token = $params['refresh_token'];
                        $this->SendDebug(__FUNCTION__, 'new refresh_token=' . $refresh_token, 0);
                        $this->WriteAttributeString('ApiRefreshToken', $refresh_token);
                    }

                    $this->SetStatus(IS_ACTIVE);
                    $this->do_AddWebhook($access_token);
                }
                break;
            default:
                $access_token = false;
                break;
        }
        return $access_token;
    }

    private function GetAppAccessToken()
    {
        $url = 'https://api.netatmo.net/oauth2/token';

        $user = $this->ReadPropertyString('Netatmo_User');
        $password = $this->ReadPropertyString('Netatmo_Password');
        $client = $this->ReadPropertyString('Netatmo_Client');
        $secret = $this->ReadPropertyString('Netatmo_Secret');

        $jtoken = json_decode($this->GetBuffer('AppAccessToken'), true);
        $access_token = isset($jtoken['access_token']) ? $jtoken['access_token'] : '';
        $expiration = isset($jtoken['expiration']) ? $jtoken['expiration'] : 0;

        if ($expiration < time()) {
            $refresh_token = $this->ReadAttributeString('AppRefreshToken');
            $auth = 'QXV0aG9yaXphdGlvbjogQmFzaWMgYm1GZlkyeHBaVzUwWDJsdmN6bzFObU5qTmpSaU56azBOak5oT1RrMU9HSTNOREF4TkRjeVpEbGxNREUxT0E9PQ==';
            $header = [
                base64_decode($auth)
            ];
            if ($refresh_token == '') {
                $postdata = [
                    'grant_type'     => 'password',
                    'username'       => $user,
                    'password'       => $password,
                    'scope'          => 'write_camera read_camera access_camera read_presence write_presence access_presence read_station read_smokedetector',
                    'app_identifier' => 'com.netatmo.camera',
                ];
            } else {
                $postdata = [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refresh_token
                ];
            }

            $data = '';
            $err = '';
            $statuscode = $this->do_HttpRequest($url, $header, $postdata, 'POST', $data, $err);
            if ($statuscode == 0) {
                $params = json_decode($data, true);
                if ($params['access_token'] == '') {
                    $statuscode = IS_INVALIDDATA;
                    $err = "no 'access_token' in response";
                }
            }

            if ($statuscode) {
                $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->SetStatus($statuscode);
                $this->SetMultiBuffer('LastData', '');
                return false;
            }

            $access_token = $params['access_token'];
            $expires_in = $params['expires_in'];
            $expiration = time() + $expires_in - 60;
            $this->SendDebug(__FUNCTION__, 'new access_token=' . $access_token . ', valid until ' . date('d.m.y H:i:s', $expiration), 0);
            $jtoken = [
                'access_token'  => $access_token,
                'expiration'    => $expiration,
            ];
            $this->SetBuffer('AppAccessToken', json_encode($jtoken));

            if (isset($params['refresh_token'])) {
                $refresh_token = $params['refresh_token'];
                $this->SendDebug(__FUNCTION__, 'new refresh_token=' . $refresh_token, 0);
                $this->WriteAttributeString('AppRefreshToken', $refresh_token);
            }
            $this->SetStatus(IS_ACTIVE);
        }
        return $access_token;
    }

    public function UpdateData()
    {
        if ($this->CheckStatus() == STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '', 0);
        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            $this->SetUpdateInterval();
            return;
        }

        $sync_event_count = $this->ReadPropertyInteger('sync_event_count');

        // Anfrage mit Token
        $url = 'https://api.netatmo.net/api/gethomedata';
        $url .= '?access_token=' . $access_token;
        $url .= '&size=' . $sync_event_count;

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
        if ($statuscode == 0) {
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            $status = $jdata['status'];
            if ($status != 'ok') {
                $err = 'got status "' . $status . '"';
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
            $this->SetBuffer('ApiAccessToken', '');
        }

        if ($statuscode) {
            $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->SetStatus($statuscode);
            $this->SetMultiBuffer('LastData', '');
            return;
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
        if ($this->CheckStatus() == STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $register_webhook = $this->ReadPropertyBoolean('register_webhook');
        if (!$register_webhook) {
            $this->SendDebug(__FUNCTION__, 'don\'t register webhook', 0);
            return;
        }

        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            return;
        }

        $this->do_AddWebhook($access_token);
    }

    private function do_AddWebhook($access_token)
    {
        $register_webhook = $this->ReadPropertyBoolean('register_webhook');
        if (!$register_webhook) {
            return;
        }

        $url = 'https://api.netatmo.net/api/addwebhook';
        $url .= '?access_token=' . $access_token;

        $webhook_baseurl = $this->ReadPropertyString('webhook_baseurl');
        if ($webhook_baseurl == '') {
            $webhook_baseurl = $this->GetConnectUrl();
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
                $err = 'got status "' . $status . '"';
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
        if ($this->CheckStatus() == STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            return;
        }

        $url = 'https://api.netatmo.net/api/dropwebhook';
        $url .= '?access_token=' . $access_token;
        $url .= '&app_types=app_security';

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
        if ($statuscode == 0) {
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            if (isset($jdata['error']['code'])) {
                $code = $jdata['error']['code'];
                if ($code != 7) { // 7 = Nothing to drop
                    $err = 'got error ' . $code;
                    if (isset($jdata['error']['messages'])) {
                        $err .= ' (' . $jdata['error']['messages'] . ')';
                    }
                    $statuscode = IS_INVALIDDATA;
                }
            } elseif (isset($jdata['status'])) {
                $status = $jdata['status'];
                if ($status != 'ok') {
                    $err = 'got status "' . $status . '"';
                    $statuscode = IS_INVALIDDATA;
                }
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

    private function SendCommand($url)
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

        $ret = json_encode(['status' => $status, 'msg' => $msg, 'data' => $data]);
        $this->SendDebug(__FUNCTION__, 'ret=' . print_r($ret, true), 0);
        return $ret;
    }

    private function SendCommandWithAuth($url, $postdata)
    {
        $inst = IPS_GetInstance($this->InstanceID);
        if ($inst['InstanceStatus'] == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return false;
        }

        $access_token = $this->GetAppAccessToken();
        if ($access_token == false) {
            return;
        }

        $header = [
            'Accept: application/json; charset=utf-8',
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json;charset=utf-8',
            'Content-Length: ' . strlen($postdata),
        ];

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, $header, $postdata, 'POST', $data, $err);
        $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);
        if ($statuscode == 0) {
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
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

        $ret = json_encode(['status' => $status, 'msg' => $msg, 'data' => $data]);
        $this->SendDebug(__FUNCTION__, 'ret=' . print_r($ret, true), 0);
        return $ret;
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
            } elseif ($httpcode == 406) {
                if (preg_match('#^https://api.netatmo.net/api/dropwebhook#', $url)) {
                    $data = $cdata;
                } else {
                    $statuscode = IS_HTTPERROR;
                    $err = 'got http-code ' . $httpcode . ' (not acceptable)';
                }
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
}
