<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class NetatmoSecurityIO extends IPSModule
{
    use NetatmoSecurity\StubsCommonLib;
    use NetatmoSecurityLocalLib;

    private $oauthIdentifer = 'netatmo';

    private static $scopes = [
        'read_presence', 'write_presence', 'access_presence', // Outdoor-Kamera
        'read_camera', 'write_camera', 'access_camera', // Indoor-Kamera
        'read_smokedetector', // Rauchmelder
        'read_carbonmonoxidedetector', // CO-Melder
        'read_doorbell', 'access_doorbell', // Türsprechanlage
    ];

    private $SemaphoreID;
    private $SemaphoreTM;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        if (defined('GRANT_PASSWORD')) {
            $this->RegisterPropertyString('Netatmo_User', '');
            $this->RegisterPropertyString('Netatmo_Password', '');
        }
        $this->RegisterPropertyString('Netatmo_Client', '');
        $this->RegisterPropertyString('Netatmo_Secret', '');

        $this->RegisterPropertyString('hook', '/hook/NetatmoSecurity');

        $this->RegisterPropertyInteger('UpdateDataInterval', '5');

        $this->RegisterPropertyBoolean('register_webhook', false);
        $this->RegisterPropertyString('webhook_baseurl', '');

        $this->RegisterPropertyInteger('sync_event_count', '30');

        $this->RegisterPropertyInteger('OAuth_Type', self::$CONNECTION_UNDEFINED);

        $this->RegisterPropertyInteger('curl_exec_timeout', 15);
        $this->RegisterPropertyInteger('curl_exec_attempts', 3);
        $this->RegisterPropertyFloat('curl_exec_delay', 1);

        $this->RegisterPropertyBoolean('collectApiCallStats', true);

        $this->RegisterAttributeString('ApiRefreshToken', '');
        $this->RegisterAttributeString('AppRefreshToken', '');

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('UpdateData', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage(0, IPS_KERNELSHUTDOWN);
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        if ($oauth_type == self::$CONNECTION_DEVELOPER) {
            if (defined('GRANT_PASSWORD')) {
                $user = $this->ReadPropertyString('Netatmo_User');
                if ($user == '') {
                    $this->SendDebug(__FUNCTION__, '"Netatmo_User" is needed', 0);
                    $r[] = $this->Translate('Username must be specified');
                }
                $password = $this->ReadPropertyString('Netatmo_Password');
                if ($password == '') {
                    $this->SendDebug(__FUNCTION__, '"Netatmo_Password" is needed', 0);
                    $r[] = $this->Translate('Password must be specified');
                }
            }
            $client_id = $this->ReadPropertyString('Netatmo_Client');
            if ($client_id == '') {
                $this->SendDebug(__FUNCTION__, '"Netatmo_Client" is needed', 0);
                $r[] = $this->Translate('Client-ID must be specified');
            }
            $client_secret = $this->ReadPropertyString('Netatmo_Secret');
            if ($client_secret == '') {
                $this->SendDebug(__FUNCTION__, '"Netatmo_Secret" is needed', 0);
                $r[] = $this->Translate('Client-Secret must be specified');
            }
        }

        $hook = $this->ReadPropertyString('hook');
        if ($hook != '' && $this->HookIsUsed($hook)) {
            $this->SendDebug(__FUNCTION__, '"hook" is already used', 0);
            $r[] = $this->Translate('Webhook is already in use');
        }

        $register_webhook = $this->ReadPropertyBoolean('register_webhook');
        if ($register_webhook) {
            $webhook_baseurl = $this->ReadPropertyString('webhook_baseurl');
            if ($webhook_baseurl == '') {
                $webhook_baseurl = $this->GetConnectUrl();
                if ($webhook_baseurl == '') {
                    $this->SendDebug(__FUNCTION__, '"webhook_baseurl" is not given', 0);
                    $r[] = $this->Translate('Neither the base URL is specified nor an active connect service is available');
                }
            }
        }

        return $r;
    }

    public function MessageSink($tstamp, $senderID, $message, $data)
    {
        parent::MessageSink($tstamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
            if ($oauth_type == self::$CONNECTION_OAUTH) {
                $this->RegisterOAuth($this->oauthIdentifer);
            }
            $hook = $this->ReadPropertyString('hook');
            if ($hook != '') {
                $this->RegisterHook($hook);
            }
            IPS_RunScriptText('IPS_RequestAction(' . $this->InstanceID . ',"AddWebhook", "");');
            $this->MaintainTimer('UpdateData', 1000);
        }
        if ($message == IPS_KERNELSHUTDOWN) {
            $register_webhook = $this->ReadPropertyBoolean('register_webhook');
            if ($register_webhook && $this->GetBuffer('ApiAccessToken') != '') {
                $this->LogMessage('drop webhook', KL_NOTIFY);
                $this->DropWebhook();
            }
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainReferences();

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $vpos = 1000;
        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        $this->MaintainMedia('ApiCallStats', $this->Translate('API call statistics'), MEDIATYPE_DOCUMENT, '.txt', false, $vpos++, $collectApiCallStats);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $curl_exec_timeout = $this->ReadPropertyInteger('curl_exec_timeout');
        $curl_exec_attempts = $this->ReadPropertyInteger('curl_exec_attempts');
        $curl_exec_delay = $this->ReadPropertyFloat('curl_exec_delay');
        $this->SemaphoreTM = ((($curl_exec_timeout + ceil($curl_exec_delay)) * $curl_exec_attempts) + 1) * 1000;

        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        if ($oauth_type == self::$CONNECTION_DEVELOPER) {
            $this->MaintainStatus(IS_ACTIVE);
        }
        if ($oauth_type == self::$CONNECTION_OAUTH) {
            if ($this->GetConnectUrl() == false) {
                $this->MaintainStatus(self::$IS_NOSYMCONCONNECT);
                return;
            }
            $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
            if ($refresh_token == '') {
                $this->MaintainStatus(self::$IS_NOLOGIN);
            } else {
                $this->MaintainStatus(IS_ACTIVE);
            }
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            if ($oauth_type == self::$CONNECTION_OAUTH) {
                $this->RegisterOAuth($this->oauthIdentifer);
            }
            $hook = $this->ReadPropertyString('hook');
            if ($hook != '') {
                $this->RegisterHook($hook);
            }
            $register_webhook = $this->ReadPropertyBoolean('register_webhook');
            if ($register_webhook) {
                $webhook_baseurl = $this->ReadPropertyString('webhook_baseurl');
                if ($webhook_baseurl == '') {
                    $webhook_baseurl = $this->GetConnectUrl();
                    if ($webhook_baseurl == '') {
                        $this->MaintainStatus(self::$IS_NOWEBHOOK);
                        return;
                    }
                }
                $this->AddWebhook();
            } else {
                $this->DropWebhook();
            }
            $this->MaintainTimer('UpdateData', 1000);
        }
    }

    private function random_string($length)
    {
        $result = '';
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        for ($i = 0; $i < $length; $i++) {
            $result .= substr($characters, rand(0, strlen($characters)), 1);
        }
        return $result;
    }

    private function build_url($url, $params)
    {
        $n = 0;
        if (is_array($params)) {
            foreach ($params as $param => $value) {
                $url .= ($n++ ? '&' : '?') . $param . '=' . rawurlencode(strval($value));
            }
        }
        return $url;
    }

    private function build_header($headerfields)
    {
        $header = [];
        foreach ($headerfields as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $header;
    }

    public function Login()
    {
        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        if ($oauth_type == self::$CONNECTION_OAUTH) {
            $params = [
                'username' => IPS_GetLicensee(),
                'scope'    => implode(' ', self::$scopes),
            ];
            $url = $this->build_url('https://oauth.ipmagic.de/authorize/' . $this->oauthIdentifer, $params);
        }
        if ($oauth_type == self::$CONNECTION_DEVELOPER) {
            $params = [
                'client_id'    => $this->ReadPropertyString('Netatmo_Client'),
                'redirect_uri' => $this->GetOAuthRedirectUri(),
                'scope'        => implode(' ', self::$scopes),
                'state'        => $this->random_string(16),
            ];
            $url = $this->build_url('https://api.netatmo.com/oauth2/authorize', $params);
        }
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    protected function Call4AccessToken($content)
    {
        $curl_exec_timeout = $this->ReadPropertyInteger('curl_exec_timeout');
        $curl_exec_attempts = $this->ReadPropertyInteger('curl_exec_attempts');
        $curl_exec_delay = $this->ReadPropertyFloat('curl_exec_delay');

        $url = 'https://oauth.ipmagic.de/access_token/' . $this->oauthIdentifer;
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '    content=' . print_r($content, true), 0);

        $headerfields = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $time_start = microtime(true);
        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $this->build_header($headerfields),
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => http_build_query($content),
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $curl_exec_timeout,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);

        $statuscode = 0;
        $err = '';
        $jbody = false;

        $attempt = 1;
        do {
            $response = curl_exec($ch);
            $cerrno = curl_errno($ch);
            $cerror = $cerrno ? curl_error($ch) : '';
            if ($cerrno) {
                $this->SendDebug(__FUNCTION__, ' => attempt=' . $attempt . ', got curl-errno ' . $cerrno . ' (' . $cerror . ')', 0);
                IPS_Sleep((int) floor($curl_exec_delay * 1000));
            }
        } while ($cerrno && $attempt++ <= $curl_exec_attempts);

        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        $httpcode = $curl_info['http_code'];

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's, attempts=' . $attempt, 0);

        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);

            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            if ($body == '' || ctype_print($body)) {
                $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);
            } else {
                $this->SendDebug(__FUNCTION__, ' => body potentially contains binary data, size=' . strlen($body), 0);
            }
        }
        if ($statuscode == 0) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_FORBIDDEN;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        }
        if ($statuscode == 0) {
            if ($body == '') {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'no data';
            } else {
                $jbody = json_decode($body, true);
                if ($jbody == '') {
                    $statuscode = self::$IS_NODATA;
                    $err = 'malformed response';
                } elseif (isset($jdata['refresh_token']) == false) {
                    $statuscode = self::$IS_INVALIDDATA;
                    $err = 'malformed response';
                }
            }
        }

        if ($statuscode) {
            $this->LogMessage('url=' . $url . ' => statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
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
                $jtoken = json_decode($data, true);
                $access_token = isset($jtoken['access_token']) ? $jtoken['access_token'] : '';
                $expiration = isset($jtoken['expiration']) ? $jtoken['expiration'] : 0;
                $type = isset($jtoken['type']) ? $jtoken['type'] : self::$CONNECTION_UNDEFINED;
                if ($type != self::$CONNECTION_OAUTH) {
                    if ($type != self::$CONNECTION_UNDEFINED) {
                        $this->WriteAttributeString('ApiRefreshToken', '');
                        $this->SendDebug(__FUNCTION__, 'connection-type changed', 0);
                    } else {
                        $this->SendDebug(__FUNCTION__, 'connection-type not set', 0);
                    }
                    $access_token = '';
                } elseif ($expiration < time()) {
                    $this->SendDebug(__FUNCTION__, 'access_token expired', 0);
                    $access_token = '';
                }
                if ($access_token != '') {
                    $this->SendDebug(__FUNCTION__, 'access_token=' . $access_token . ', valid until ' . date('d.m.y H:i:s', $expiration), 0);
                    return $access_token;
                }
            } else {
                $this->SendDebug(__FUNCTION__, 'no saved access_token', 0);
            }
            $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
            $this->SendDebug(__FUNCTION__, 'refresh_token=' . print_r($refresh_token, true), 0);
            if ($refresh_token == '') {
                $this->SendDebug(__FUNCTION__, 'has no refresh_token', 0);
                $this->WriteAttributeString('ApiRefreshToken', '');
                $this->SetBuffer('ApiAccessToken', '');
                $this->MaintainTimer('UpdateData', 0);
                $this->MaintainStatus(self::$IS_NOLOGIN);
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
        $jtoken = [
            'access_token' => $access_token,
            'expiration'   => $expiration,
            'type'         => self::$CONNECTION_OAUTH
        ];
        $this->SetBuffer('ApiAccessToken', json_encode($jtoken));
        $this->do_AddWebhook($access_token);
        return $access_token;
    }

    protected function ProcessOAuthData()
    {
        if (!isset($_GET['code'])) {
            $this->SendDebug(__FUNCTION__, 'code missing, _GET=' . print_r($_GET, true), 0);
            $this->WriteAttributeString('ApiRefreshToken', '');
            $this->SetBuffer('ApiAccessToken', '');
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_NOLOGIN);
            return;
        }
        $refresh_token = $this->FetchRefreshToken($_GET['code']);
        $this->SendDebug(__FUNCTION__, 'refresh_token=' . $refresh_token, 0);
        $this->WriteAttributeString('ApiRefreshToken', $refresh_token);
        if ($this->GetStatus() == self::$IS_NOLOGIN) {
            $this->MaintainTimer('UpdateData', 1000);
            $this->MaintainStatus(IS_ACTIVE);
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Netatmo Security I/O');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        if ($oauth_type == self::$CONNECTION_OAUTH || ($oauth_type == self::$CONNECTION_DEVELOPER && $this->GetConnectUrl() !== false)) {
            $formElements[] = [
                'type'    => 'Label',
                'caption' => $this->GetConnectStatusText(),
            ];
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'    => 'Select',
            'name'    => 'OAuth_Type',
            'caption' => 'Connection Type',
            'options' => [
                [
                    'caption' => 'Please select a connection type',
                    'value'   => self::$CONNECTION_UNDEFINED
                ],
                [
                    'caption' => 'Netatmo via IP-Symcon Connect',
                    'value'   => self::$CONNECTION_OAUTH
                ],
                [
                    'caption' => 'Netatmo Developer Key',
                    'value'   => self::$CONNECTION_DEVELOPER
                ]
            ]
        ];

        if ($oauth_type == self::$CONNECTION_DEVELOPER) {
            $items = [];
            if (defined('GRANT_PASSWORD')) {
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
            }
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
                'width'   => '400px',
                'name'    => 'Netatmo_Secret',
                'caption' => 'Client Secret'
            ];
            if (!defined('GRANT_PASSWORD')) {
                if ($this->GetConnectUrl() == false) {
                    $items[] = [
                        'type'    => 'Label',
                        'caption' => 'Due to the API changes, password-based login with the developer key is no longer possible. Instead, the login must be triggered manually. Alternatively, the refresh token can be entered manually; see expert panel.',
                    ];
                    $items[] = [
                        'type'    => 'ValidationTextBox',
                        'width'   => '600px',
                        'caption' => 'Refresh token',
                        'value'   => $this->ReadAttributeString('ApiRefreshToken'),
                        'enabled' => false,
                    ];
                } else {
                    $captions = [
                        'Due to the API changes, password-based login with the developer key is no longer possible. Instead, the login must be triggered manually.',
                        '',
                        'Push "Login at Netatmo" in the action part of this configuration form.',
                        'At the webpage from Netatmo log in with your Netatmo username and password.',
                        'If the connection was successfull you get the message: "Login was successful". Close the browser window.',
                        'Return to this configuration form.',
                    ];
                    foreach ($captions as $caption) {
                        $items[] = [
                            'type'    => 'Label',
                            'caption' => $caption,
                        ];
                    }
                }
            }
            $formElements[] = [
                'type'    => 'ExpansionPanel',
                'items'   => $items,
                'caption' => 'Netatmo Access-Details'
            ];
        }
        if ($oauth_type == self::$CONNECTION_OAUTH) {
            $items = [];
            $captions = [
                'Push "Login at Netatmo" in the action part of this configuration form.',
                'At the webpage from Netatmo log in with your Netatmo username and password.',
                'If the connection to IP-Symcon was successfull you get the message: "Netatmo successfully connected!". Close the browser window.',
                'Return to this configuration form.',
            ];
            foreach ($captions as $caption) {
                $items[] = [
                    'type'    => 'Label',
                    'caption' => $caption,
                ];
            }
            $formElements[] = [
                'type'    => 'ExpansionPanel',
                'items'   => $items,
                'caption' => 'Netatmo Login'
            ];
        }

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'NumberSpinner',
                    'minimum' => 0,
                    'suffix'  => 'Minutes',
                    'name'    => 'UpdateDataInterval',
                    'caption' => 'Update interval'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'minimum' => 0,
                    'name'    => 'sync_event_count',
                    'caption' => 'Number of events per update'
                ],
            ],
            'caption' => 'Call settings'
        ];

        $items = [
            [
                'type'    => 'Label',
                'caption' => 'Webhook for receive notifications from Netatmo (must be reachable from internet)'
            ],
            [
                'type'    => 'CheckBox',
                'name'    => 'register_webhook',
                'caption' => 'Register Webhook'
            ],
            [
                'type'    => 'Label',
                'caption' => $this->Translate('the base-URL will extended with') . ' "' . $this->ReadPropertyString('hook') . '/event"'
            ],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'webhook_baseurl',
                'caption' => 'Base-URL'
            ],
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

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'Label',
                    'caption' => 'Behavior of HTTP requests at the technical level'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'minimum' => 0,
                    'suffix'  => 'Seconds',
                    'name'    => 'curl_exec_timeout',
                    'caption' => 'Timeout of an HTTP call'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'minimum' => 0,
                    'name'    => 'curl_exec_attempts',
                    'caption' => 'Number of attempts after communication failure'
                ],
                [
                    'type'     => 'NumberSpinner',
                    'minimum'  => 0.1,
                    'maximum'  => 60,
                    'digits'   => 1,
                    'suffix'   => 'Seconds',
                    'name'     => 'curl_exec_delay',
                    'caption'  => 'Delay between attempts'
                ],
            ],
            'caption' => 'Communication'
        ];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'collectApiCallStats',
            'caption' => 'Collect data of API calls'
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

        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        if ($oauth_type != self::$CONNECTION_UNDEFINED) {
            $formActions[] = [
                'type'    => 'Button',
                'caption' => 'Login at Netatmo',
                'onClick' => 'echo "' . $this->Login() . '";',
            ];
        }

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Update data',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", "");',
        ];

        $items = [];

        $items[] = [
            'type'    => 'Button',
            'caption' => 'Register Webhook',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "AddWebhook", "");',
        ];

        $items[] = [
            'type'    => 'Button',
            'caption' => 'Clear token',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "ClearToken", "");',
        ];

        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        if ($oauth_type == self::$CONNECTION_DEVELOPER) {
            if ($this->GetConnectUrl() == false) {
                $items[] = [
                    'type'     => 'PopupButton',
                    'caption'  => 'Set refresh token',
                    'popup'    => [
                        'caption'  => 'Set refresh token',
                        'items'    => [
                            [
                                'type'    => 'Label',
                                'caption' => 'Generate the refresh token at https://dev.netatmo.com/apps/ for the app you are using. The scopes must be selected according to the default',
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => $this->Translate('Needed scopes') . ': ' . implode(' ', self::$scopes),
                            ],
                            [
                                'type'    => 'ValidationTextBox',
                                'width'   => '600px',
                                'name'    => 'refresh_token',
                                'caption' => 'Refresh token'
                            ],
                        ],
                        'buttons' => [
                            [
                                'caption' => 'Set',
                                'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "SetRefreshToken", $refresh_token);',
                            ],
                        ],
                    ],
                ];
            }
        }

        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        if ($collectApiCallStats) {
            $items[] = $this->GetApiCallStatsFormItem();
        }

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => $items,
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'UpdateData':
                $this->UpdateData();
                break;
            case 'AddWebhook':
                $this->AddWebhook();
                break;
            case 'ClearToken':
                $this->ClearToken();
                break;
            case 'SetRefreshToken':
                $this->SetRefreshToken($value);
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }

    private function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('UpdateDataInterval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->MaintainTimer('UpdateData', $msec);
    }

    protected function SendData($data, $source)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);
        $this->SendDataToChildren(json_encode(['DataID' => '{5F947426-53FB-4DD9-A725-F95590CBD97C}', 'Source' => $source, 'Buffer' => $data]));
    }

    public function ForwardData($data)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
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
                    $this->MaintainTimer('UpdateData', 1000);
                    break;
                case 'CmdUrlGetWithAuth':
                    $url = $jdata['Url'];
                    $url .= '&access_token=' . $this->GetApiAccessToken();
                    $ret = $this->SendCommand($url);
                    $this->MaintainTimer('UpdateData', 1000);
                    break;
                case 'CmdUrlPostWithAuth':
                    $url = $jdata['Url'];
                    $postdata = $jdata['PostData'];
                    $ret = $this->SendCommandWithAuth($url, $postdata);
                    $this->MaintainTimer('UpdateData', 1000);
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

    private function GetOAuthRedirectUri()
    {
        return $this->GetConnectUrl() . $this->ReadPropertyString('hook') . '/oauth';
    }

    private function OAuthStep2($data)
    {
        if (isset($data['code']) == false) {
            $this->SendDebug(__FUNCTION__, 'item "code" ist missing in data=' . print_r($data, true), 0);
            $this->MaintainStatus(self::$IS_INVALIDDATA);
            $this->SetMultiBuffer('LastData', '');
            return false;
        }

        $url = 'https://api.netatmo.com/oauth2/token';

        $client_id = $this->ReadPropertyString('Netatmo_Client');
        $client_secret = $this->ReadPropertyString('Netatmo_Secret');

        $headerfields = [
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        ];
        $postfields = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'code'          => $data['code'],
            'redirect_uri'  => $this->GetOAuthRedirectUri(),
            'scope'         => implode(' ', self::$scopes),
        ];

        $this->SendDebug(__FUNCTION__, 'postfields=' . print_r($postfields, true), 0);
        $header = $this->build_header($headerfields);
        $postdata = http_build_query($postfields);

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, $header, $postdata, 'POST', $data, $err);
        if ($statuscode == 0) {
            $params = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'params=' . print_r($params, true), 0);
            if ($params['access_token'] == '') {
                $statuscode = self::$IS_INVALIDDATA;
                $err = "no 'access_token' in response";
            }
        }

        if ($statuscode) {
            $this->LogMessage('url=' . $url . ', statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->MaintainStatus($statuscode);
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
            'type'         => self::$CONNECTION_DEVELOPER
        ];
        $this->SetBuffer('ApiAccessToken', json_encode($jtoken));

        if (isset($params['refresh_token'])) {
            $refresh_token = $params['refresh_token'];
            $this->SendDebug(__FUNCTION__, 'new refresh_token=' . $refresh_token, 0);
            $this->WriteAttributeString('ApiRefreshToken', $refresh_token);
        }

        $this->MaintainStatus(IS_ACTIVE);
    }

    private function GetApiAccessToken()
    {
        $oauth_type = $this->ReadPropertyInteger('OAuth_Type');
        switch ($oauth_type) {
            case self::$CONNECTION_OAUTH:
                $access_token = $this->FetchAccessToken();
                break;
            case self::$CONNECTION_DEVELOPER:
                $url = 'https://api.netatmo.com/oauth2/token';

                $client_id = $this->ReadPropertyString('Netatmo_Client');
                $client_secret = $this->ReadPropertyString('Netatmo_Secret');

                $jtoken = json_decode($this->GetBuffer('ApiAccessToken'), true);
                $access_token = isset($jtoken['access_token']) ? $jtoken['access_token'] : '';
                $expiration = isset($jtoken['expiration']) ? $jtoken['expiration'] : 0;
                $type = isset($jtoken['type']) ? $jtoken['type'] : self::$CONNECTION_UNDEFINED;
                if ($type != self::$CONNECTION_DEVELOPER) {
                    if ($type != self::$CONNECTION_UNDEFINED) {
                        $this->WriteAttributeString('ApiRefreshToken', '');
                        $this->SendDebug(__FUNCTION__, 'connection-type changed', 0);
                    } else {
                        $this->SendDebug(__FUNCTION__, 'connection-type not set', 0);
                    }
                    $access_token = '';
                } elseif ($expiration < time()) {
                    $this->SendDebug(__FUNCTION__, 'access_token expired', 0);
                    $access_token = '';
                }
                if ($access_token != '') {
                    $this->SendDebug(__FUNCTION__, 'old access_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);
                } else {
                    $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
                    if ($refresh_token == '') {
                        if (defined('GRANT_PASSWORD')) {
                            $user = $this->ReadPropertyString('Netatmo_User');
                            $password = $this->ReadPropertyString('Netatmo_Password');
                            $postdata = [
                                'grant_type'    => 'password',
                                'client_id'     => $client_id,
                                'client_secret' => $client_secret,
                                'username'      => $user,
                                'password'      => $password,
                                'scope'         => implode(' ', self::$scopes),
                            ];
                        } else {
                            $this->SendDebug(__FUNCTION__, 'grant_type "password" is not longer supported, set "refresh_token" manually', 0);
                            $this->MaintainStatus(self::$IS_NOLOGIN);
                            return false;
                        }
                    } else {
                        $postdata = [
                            'grant_type'    => 'refresh_token',
                            'client_id'     => $client_id,
                            'client_secret' => $client_secret,
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
                            $statuscode = self::$IS_INVALIDDATA;
                            $err = "no 'access_token' in response";
                        }
                    }

                    if ($statuscode) {
                        $this->LogMessage('url=' . $url . ', statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                        $this->SendDebug(__FUNCTION__, $err, 0);
                        $this->MaintainStatus($statuscode);
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
                        'type'         => self::$CONNECTION_DEVELOPER
                    ];
                    $this->SetBuffer('ApiAccessToken', json_encode($jtoken));

                    if (isset($params['refresh_token'])) {
                        $refresh_token = $params['refresh_token'];
                        $this->SendDebug(__FUNCTION__, 'new refresh_token=' . $refresh_token, 0);
                        $this->WriteAttributeString('ApiRefreshToken', $refresh_token);
                    }

                    $this->MaintainStatus(IS_ACTIVE);
                    $this->do_AddWebhook($access_token);
                }

                break;
            default:
                $access_token = false;
                break;
        }
        return $access_token;
    }

    private function UpdateData()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            if ($this->GetStatus() == self::$IS_NOLOGIN) {
                $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => pause', 0);
                $this->MaintainTimer('UpdateData', 0);
            } else {
                $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            }
            return;
        }

        $this->SendDebug(__FUNCTION__, '', 0);
        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            if ($this->GetStatus() == self::$IS_NOLOGIN) {
                $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => pause', 0);
                $this->MaintainTimer('UpdateData', 0);
            } else {
                $this->SetUpdateInterval();
            }
            return;
        }

        $sync_event_count = $this->ReadPropertyInteger('sync_event_count');

        $url = 'https://app.netatmo.net/api/homesdata';

        $postdata = [
            'gateway_types' => ['NACamera', 'NOC', 'NDB', 'NSD', 'NCO'],
        ];
        $pdata = json_encode($postdata);

        $header = [
            'Accept: application/json',
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json;charset=utf-8',
        ];

        $data = '';
        $err = '';
        $statuscode = $this->do_HttpRequest($url, $header, $pdata, 'POST', $data, $err);
        $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);
        if ($statuscode == 0) {
            $jconfig = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jconfig=' . print_r($jconfig, true), 0);
            $status = $jconfig['status'];
            if ($status != 'ok') {
                $err = 'got status "' . $status . '"';
                $statuscode = self::$IS_INVALIDDATA;
            }
        }
        if ($statuscode) {
            if ($statuscode == self::$IS_FORBIDDEN) {
                $err .= ' => 15min pause';
                $this->MaintainTimer('UpdateData', 15 * 60 * 1000);
                $this->SetBuffer('ApiAccessToken', '');
            } else {
                $this->SetUpdateInterval();
            }

            $this->LogMessage('url=' . $url . ', statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->SetMultiBuffer('LastData', '');

            $this->MaintainStatus($statuscode);
            return;
        }

        $jdata_states = [
            'homes' => [],
        ];
        $jdata_events = [
            'homes' => [],
        ];

        if (isset($jconfig['body']['homes'])) {
            $homes = $jconfig['body']['homes'];
            foreach ($homes as $home) {
                $this->SendDebug(__FUNCTION__, 'home=' . print_r($home, true), 0);

                $url = 'https://app.netatmo.net/syncapi/v1/homestatus';

                $postdata = [
                    'home_id'      => $home['id'],
                    'device_types' => ['NACamera', 'NOC', 'NDB', 'NSD', 'NCO'],
                ];
                $pdata = json_encode($postdata);

                $header = [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json;charset=utf-8',
                ];

                $data = '';
                $err = '';
                $statuscode = $this->do_HttpRequest($url, $header, $pdata, 'POST', $data, $err);
                $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);
                if ($statuscode == 0) {
                    $jstates = json_decode($data, true);
                    $this->SendDebug(__FUNCTION__, 'jstates=' . print_r($jstates, true), 0);
                }
                if ($statuscode) {
                    if ($statuscode == self::$IS_FORBIDDEN) {
                        $err .= ' => 15min pause';
                        $this->MaintainTimer('UpdateData', 15 * 60 * 1000);
                        $this->SetBuffer('ApiAccessToken', '');
                    } else {
                        $this->SetUpdateInterval();
                    }

                    $this->LogMessage('url=' . $url . ', statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                    $this->SendDebug(__FUNCTION__, $err, 0);
                    $this->SetMultiBuffer('LastData', '');

                    $this->MaintainStatus($statuscode);
                    return;
                }

                $params = [
                    'home_id' => $home['id'],
                    'size'    => $sync_event_count
                ];
                $url = $this->build_url('https://api.netatmo.com/api/getevents', $params);

                $header = [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $access_token,
                ];

                $data = '';
                $err = '';
                $statuscode = $this->do_HttpRequest($url, $header, '', 'GET', $data, $err);
                $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);
                if ($statuscode == 0) {
                    $jevents = json_decode($data, true);
                    $this->SendDebug(__FUNCTION__, 'jevents=' . print_r($jevents, true), 0);
                }
                if ($statuscode) {
                    if ($statuscode == self::$IS_FORBIDDEN) {
                        $err .= ' => 15min pause';
                        $this->MaintainTimer('UpdateData', 15 * 60 * 1000);
                        $this->SetBuffer('ApiAccessToken', '');
                    } else {
                        $this->SetUpdateInterval();
                    }

                    $this->LogMessage('url=' . $url . ', statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
                    $this->SendDebug(__FUNCTION__, $err, 0);
                    $this->SetMultiBuffer('LastData', '');

                    $this->MaintainStatus($statuscode);
                    return;
                }

                if (isset($jevents['body']['home']['events'])) {
                    $jstates['body']['home']['events'] = $jevents['body']['home']['events'];
                }
                if (isset($jstates['body']['home'])) {
                    $jdata_states_homes = $jdata_states['homes'];
                    $jdata_states_homes[] = $jstates['body']['home'];
                    $jdata_states['homes'] = $jdata_states_homes;
                }
            }
        }

        $jdata = [
            'status'      => $jconfig['status'],
            'time_exec'   => $jconfig['time_exec'],
            'time_server' => $jconfig['time_server'],
            'config'      => $jconfig['body'],
            'states'      => $jdata_states,
            'events'      => $jdata_events,
        ];
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
        $data = json_encode($jdata);
        $this->SendData($data, 'QUERY');
        $this->SetMultiBuffer('LastData', $data);

        $this->MaintainStatus(IS_ACTIVE);

        $this->SetUpdateInterval();
    }

    protected function ProcessHookData()
    {
        $this->SendDebug(__FUNCTION__, '_SERVER=' . print_r($_SERVER, true), 0);
        $this->SendDebug(__FUNCTION__, '_GET=' . print_r($_GET, true), 0);

        $root = realpath(__DIR__);
        $uri = $_SERVER['REQUEST_URI'];
        if (substr($uri, -1) == '/') {
            http_response_code(404);
            die('File not found!');
        }
        $hook = $this->ReadPropertyString('hook');
        if ($hook == '') {
            http_response_code(404);
            die('File not found!');
        }
        $basename = substr($uri, strlen($hook . '/'));
        if ($basename == 'event') {
            $data = file_get_contents('php://input');
            $jdata = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            if ($jdata == '') {
                http_response_code(406);
                die('malformed data!');
            }
            http_response_code(200);
            $this->SendData($data, 'PUSH');
            $this->MaintainTimer('UpdateData', 5000);
            return;
        }
        if ($basename == 'oauth') {
            $this->OAuthStep2($_GET);
            if ($this->GetStatus() == IS_ACTIVE) {
                echo $this->Translate('Login was successful');
            } else {
                echo $this->Translate('Login was not successful');
            }
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

    private function ClearToken()
    {
        $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
        $this->SendDebug(__FUNCTION__, 'clear refresh_token=' . $refresh_token, 0);
        $this->WriteAttributeString('ApiRefreshToken', '');

        $access_token = $this->GetApiAccessToken();
        $this->SendDebug(__FUNCTION__, 'clear access_token=' . $access_token, 0);
        $this->SetBuffer('ApiAccessToken', '');
    }

    private function SetRefreshToken($refresh_token)
    {
        $this->SendDebug(__FUNCTION__, 'set refresh_token=' . $refresh_token, 0);
        $this->WriteAttributeString('ApiRefreshToken', $refresh_token);
        $jtoken = [
            'access_token' => '',
            'expiration'   => 0,
            'type'         => self::$CONNECTION_DEVELOPER
        ];
        $this->SetBuffer('ApiAccessToken', json_encode($jtoken));
        $this->GetApiAccessToken();
        $this->ReloadForm();
    }

    private function AddWebhook()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
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

        $params = [
            'access_token' => $access_token,
        ];
        $url = $this->build_url('https://api.netatmo.com/api/addwebhook', $params);

        $webhook_baseurl = $this->ReadPropertyString('webhook_baseurl');
        if ($webhook_baseurl == '') {
            $webhook_baseurl = $this->GetConnectUrl();
            if ($webhook_baseurl == '') {
                $this->SendDebug(__FUNCTION__, 'webhook missing', 0);
                return;
            }
        }
        $hook = $this->ReadPropertyString('hook');
        if ($hook == '') {
            $this->SendDebug(__FUNCTION__, 'hook empty', 0);
            return;
        }
        $webhook_baseurl .= $hook . '/event';
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
                $statuscode = self::$IS_INVALIDDATA;
            }
        }
        if ($statuscode) {
            $this->LogMessage('url=' . $url . ', statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->MaintainStatus($statuscode);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);
    }

    public function DropWebhook()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            return;
        }

        $params = [
            'access_token' => $access_token,
            'app_types'    => 'app_security',
        ];
        $url = $this->build_url('https://api.netatmo.com/api/dropwebhook', $params);

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
                    $statuscode = self::$IS_INVALIDDATA;
                }
            } elseif (isset($jdata['status'])) {
                $status = $jdata['status'];
                if ($status != 'ok') {
                    $err = 'got status "' . $status . '"';
                    $statuscode = self::$IS_INVALIDDATA;
                }
            }
        }
        if ($statuscode) {
            $this->LogMessage('url=' . $url . ', statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->MaintainStatus($statuscode);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);
    }

    private function SendCommand($url)
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
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
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            return;
        }

        $header = [
            'Accept: application/json',
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json;charset=utf-8',
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
        $curl_exec_timeout = $this->ReadPropertyInteger('curl_exec_timeout');
        $curl_exec_attempts = $this->ReadPropertyInteger('curl_exec_attempts');
        $curl_exec_delay = $this->ReadPropertyFloat('curl_exec_delay');

        $this->SendDebug(__FUNCTION__, 'http-' . $mode . ': url=' . $url, 0);

        if ($header != '') {
            $this->SendDebug(__FUNCTION__, '    header=' . print_r($header, true), 0);
        }
        if ($postdata != '') {
            $this->SendDebug(__FUNCTION__, '    postdata=' . print_r($postdata, true), 0);
        }

        $time_start = microtime(true);

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

        $attempt = 1;
        do {
            $cdata = curl_exec($ch);
            $cerrno = curl_errno($ch);
            $cerror = $cerrno ? curl_error($ch) : '';
            if ($cerrno) {
                $this->SendDebug(__FUNCTION__, ' => attempt=' . $attempt . ', got curl-errno ' . $cerrno . ' (' . $cerror . ')', 0);
                IPS_Sleep((int) floor($curl_exec_delay * 1000));
            }
        } while ($cerrno && $attempt++ <= $curl_exec_attempts);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's, attempts=' . $attempt, 0);
        $this->SendDebug(__FUNCTION__, '    cdata=' . $cdata, 0);

        $statuscode = 0;
        $err = '';
        $data = '';

        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } elseif ($httpcode != 200) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_FORBIDDEN;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode == 406) {
                if (preg_match('#^https://api.netatmo.com/api/dropwebhook#', $url)) {
                    $data = $cdata;
                } else {
                    $statuscode = self::$IS_HTTPERROR;
                    $err = 'got http-code ' . $httpcode . ' (not acceptable)';
                }
            } elseif ($httpcode == 409) {
                $data = $cdata;
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } else {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        } elseif ($cdata == '') {
            $statuscode = self::$IS_NODATA;
            $err = 'no data';
        } else {
            $jdata = json_decode($cdata, true);
            if ($jdata == '') {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'malformed response';
            } else {
                $data = $cdata;
            }
        }

        $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
        if ($data != $cdata) {
            $this->SendDebug(__FUNCTION__, '    data=' . $data, 0);
        }

        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        if ($collectApiCallStats) {
            $this->ApiCallCollect($url, $err, $statuscode);
        }

        return $statuscode;
    }
}
