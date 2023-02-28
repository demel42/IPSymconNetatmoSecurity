<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class NetatmoSecurityCamera extends IPSModule
{
    use NetatmoSecurity\StubsCommonLib;
    use NetatmoSecurityLocalLib;

    private $ModuleDir;

    public static $MOTION_RELEASE = 60; // Sekunden

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('product_type', '');
        $this->RegisterPropertyString('product_id', '');
        $this->RegisterPropertyString('home_id', '');

        $this->RegisterPropertyBoolean('with_last_contact', false);
        $this->RegisterPropertyBoolean('with_last_event', false);
        $this->RegisterPropertyBoolean('with_last_notification', false);
        $this->RegisterPropertyBoolean('with_wifi_strength', false);

        $this->RegisterPropertyBoolean('with_motion_detection', false);

        $this->RegisterPropertyString('hook', '');

        $this->RegisterPropertyString('ipsIP', '');
        $this->RegisterPropertyInteger('ipsPort', 3777);
        $this->RegisterPropertyString('externalIP', '');
        $this->RegisterPropertyString('localCIDRs', '');

        $this->RegisterPropertyInteger('event_max_age', 14);
        $this->RegisterPropertyInteger('notification_max_age', 2);

        $this->RegisterPropertyString('ftp_path', '');
        $this->RegisterPropertyInteger('ftp_max_age', 14);

        $this->RegisterPropertyString('timelapse_path', '');
        $this->RegisterPropertyInteger('timelapse_hour', 0);
        $this->RegisterPropertyInteger('timelapse_max_age', 7);

        $this->RegisterPropertyInteger('new_event_script', 0);
        $this->RegisterPropertyInteger('notify_script', 0);
        $this->RegisterPropertyInteger('webhook_script', 0);
        $this->RegisterPropertyInteger('url_changed_script', 0);

        $this->RegisterPropertyInteger('ImportCategoryID', 0);

        $this->RegisterAttributeString('UpdateInfo', '');

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

        $this->RegisterTimer('CleanupPath', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "doCleanupPath", "");');
        $this->RegisterTimer('LoadTimelapse', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "doLoadTimelapse", "");');
        $this->RegisterTimer('MotionRelease', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "doMotionRelease", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $product_type = $this->ReadPropertyString('product_type');
        if ($product_type == '') {
            $this->SendDebug(__FUNCTION__, '"product_type" is empty', 0);
            $r[] = $this->Translate('Product-Type must be specified');
        }

        $product_id = $this->ReadPropertyString('product_id');
        if ($product_id == '') {
            $this->SendDebug(__FUNCTION__, '"product_id" is empty', 0);
            $r[] = $this->Translate('Product-ID must be specified');
        }

        $home_id = $this->ReadPropertyString('home_id');
        if ($home_id == '') {
            $this->SendDebug(__FUNCTION__, '"home_id" is empty', 0);
            $r[] = $this->Translate('Home-ID must be specified');
        }

        $timelapse_path = $this->ReadPropertyString('timelapse_path');
        if ($timelapse_path != '') {
            $timelapse_hour = $this->ReadPropertyInteger('timelapse_hour');
            if ($timelapse_hour < -1 || $timelapse_hour > 23) {
                $this->SendDebug(__FUNCTION__, '"timelapse_hour" is invalid', 0);
                $r[] = $this->Translate('"hour of day" is invalid');
            }
        }

        $ipsIP = $this->ReadPropertyString('ipsIP');
        if ($ipsIP != '') {
            if ($this->deternmineIp($ipsIP) == false) {
                $this->SendDebug(__FUNCTION__, '"ipsIP" is invalid', 0);
                $r[] = $this->Translate('IP-Address is invalid');
            }
        }

        $externalIP = $this->ReadPropertyString('externalIP');
        if ($externalIP != '') {
            if ($this->deternmineIp($externalIP) == false) {
                $this->SendDebug(__FUNCTION__, '"externalIP" is invalid', 0);
                $r[] = $this->Translate('external IP-Address is invalid');
            }
        }

        $localCIDRs = $this->ReadPropertyString('localCIDRs');
        if ($localCIDRs != '') {
            $ok = true;
            $cidrs = explode(';', $localCIDRs);
            foreach ($cidrs as $cidr) {
                list($net, $mask) = explode('/', $cidr);
                if (ip2long($net) == false) {
                    $ok = false;
                }
                if (preg_match('/[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}/', $mask)) {
                    if (ip2long($mask) == false) {
                        $ok = false;
                    }
                } else {
                    if (!is_numeric($mask) || $mask < 1 || $mask > 31) {
                        $ok = false;
                    }
                }
                if (!$ok) {
                    break;
                }
            }
            if (!$ok) {
                $this->SendDebug(__FUNCTION__, '"localCIDRs" is invalid', 0);
                $r[] = $this->Translate('local CIDR\'s are invalid');
            }
        }

        $hook = $this->ReadPropertyString('hook');
        if ($hook != '' && $this->HookIsUsed($hook)) {
            $this->SendDebug(__FUNCTION__, '"hook" is already used', 0);
            $r[] = $this->Translate('Webhook is already in use');
        }

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['ImportCategoryID', 'new_event_script', 'notify_script', 'url_changed_script', 'webhook_script'];
        $this->MaintainReferences($propertyNames);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('CleanupPath', 0);
            $this->MaintainTimer('LoadTimelapse', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('CleanupPath', 0);
            $this->MaintainTimer('LoadTimelapse', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('CleanupPath', 0);
            $this->MaintainTimer('LoadTimelapse', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $with_last_contact = $this->ReadPropertyBoolean('with_last_contact');
        $with_last_event = $this->ReadPropertyBoolean('with_last_event');
        $with_last_notification = $this->ReadPropertyBoolean('with_last_notification');
        $with_wifi_strength = $this->ReadPropertyBoolean('with_wifi_strength');
        $with_motion_detection = $this->ReadPropertyBoolean('with_motion_detection');

        $product_type = $this->ReadPropertyString('product_type');
        switch ($product_type) {
            case 'NACamera':
                $with_light = false;
                $with_power = true;
                break;
            case 'NOC':
                $with_light = true;
                $with_power = false;
                break;
            default:
                $with_light = false;
                $with_power = false;
                break;
        }

        $vpos = 1;

        $this->MaintainVariable('Status', $this->Translate('State'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $vpos++, true);
        $this->MaintainVariable('LastContact', $this->Translate('Last communication'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_contact);
        $this->MaintainVariable('LastEvent', $this->Translate('Last event'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_event);
        $this->MaintainVariable('LastNotification', $this->Translate('Last notification'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_notification);

        $this->MaintainVariable('CameraStatus', $this->Translate('Camera state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.CameraStatus', $vpos++, true);
        $this->MaintainVariable('SDCardStatus', $this->Translate('SD-Card state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.SDCardStatus', $vpos++, true);
        $this->MaintainVariable('PowerStatus', $this->Translate('Power state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.PowerStatus', $vpos++, $with_power);
        $this->MaintainVariable('LightmodeStatus', $this->Translate('Lightmode state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.LightModeStatus', $vpos++, $with_light);

        $this->MaintainVariable('CameraAction', $this->Translate('Camera operation'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.CameraAction', $vpos++, true);
        $this->MaintainVariable('LightAction', $this->Translate('Light operation'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.LightAction', $vpos++, $with_light);
        $this->MaintainVariable('LightIntensity', $this->Translate('Light intensity'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.LightIntensity', $vpos++, $with_light);

        $this->MaintainAction('CameraAction', true);
        if ($with_light) {
            $this->MaintainAction('LightAction', true);
            $this->MaintainAction('LightIntensity', true);
        }

        $this->MaintainVariable('WifiStrength', $this->Translate('Strength of wifi-signal'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.WifiStrength', $vpos++, $with_wifi_strength);

        $this->MaintainVariable('MotionType', $this->Translate('Motion detected'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.MotionType', $vpos++, $with_motion_detection);

        $product_id = $this->ReadPropertyString('product_id');
        $product_type = $this->ReadPropertyString('product_type');
        $product_info = $product_id . ' (' . $product_type . ')';
        $this->SetSummary($product_info);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        /*
        $dataFilter = '.*id[^:]*:["]*' . $product_id . '.*';
        $this->SendDebug(__FUNCTION__, 'set ReceiveDataFilter=' . $dataFilter, 0);
        $this->SetReceiveDataFilter($dataFilter);
         */

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $hook = $this->ReadPropertyString('hook');
            if ($hook != '') {
                $this->RegisterHook($hook);
                $this->SendDebug(__FUNCTION__, 'webhook-url=' . $this->GetServerUrl() . $hook, 0);
                if ($this->GetServerUrl() != $this->GetLocalServerUrl()) {
                    $this->SendDebug(__FUNCTION__, 'webhook-url=' . $this->GetLocalServerUrl() . $hook, 0);
                }
            }
            $this->SetTimer();
        }

        $this->MaintainStatus(IS_ACTIVE);
    }

    public function MessageSink($tstamp, $senderID, $message, $data)
    {
        parent::MessageSink($tstamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $hook = $this->ReadPropertyString('hook');
            if ($hook != '') {
                $this->RegisterHook($hook);
                $this->SendDebug(__FUNCTION__, 'webhook-url=' . $this->GetServerUrl() . $hook, 0);
                if ($this->GetServerUrl() != $this->GetLocalServerUrl()) {
                    $this->SendDebug(__FUNCTION__, 'webhook-url=' . $this->GetLocalServerUrl() . $hook, 0);
                }
            }
            $this->SetTimer();
        }
    }

    private function SetTimer()
    {
        $this->SendDebug(__FUNCTION__, $this->PrintTimer('LoadTimelapse'), 0);
        $timer = $this->GetTimerByName('LoadTimelapse');
        if ($timer['NextRun'] <= time()) {
            $timelapse_path = $this->ReadPropertyString('timelapse_path');
            $timelapse_hour = $this->ReadPropertyInteger('timelapse_hour');
            if ($timelapse_path != '' && $timelapse_hour >= 0 && $timelapse_hour < 24) {
                $fmt = sprintf('d.m.Y 00:%02d:01', $timelapse_hour);
                $dt = new DateTime(date($fmt, time() + (24 * 60 * 60)));
                $ts = (int) $dt->format('U');
                $msec = ($ts - time()) * 1000;
                $this->MaintainTimer('LoadTimelapse', $msec);
            }
        }

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('CleanupPath'), 0);
        $timer = $this->GetTimerByName('CleanupPath');
        if ($timer['NextRun'] <= time()) {
            $ftp_max_age = $this->ReadPropertyInteger('ftp_max_age');
            $timelapse_max_age = $this->ReadPropertyInteger('timelapse_max_age');
            if ($ftp_max_age > 0 || $timelapse_max_age > 0) {
                $dt = new DateTime(date('d.m.Y 00:30:00', time() + (24 * 60 * 60)));
                $ts = (int) $dt->format('U');
                $msec = ($ts - time()) * 1000;
                $this->MaintainTimer('CleanupPath', $msec);
            }
        }
    }

    private function getConfiguratorValues()
    {
        $entries = [];

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return $entries;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            return $entries;
        }

        $catID = $this->ReadPropertyInteger('ImportCategoryID');

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'LastData'];
        $data = $this->SendDataToParent(json_encode($SendData));
        $jdata = json_decode($data, true);

        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

        $guid = '{7FAAE2B1-D5E8-4E51-9161-85F82EEE79DC}';
        $instIDs = IPS_GetInstanceListByModuleID($guid);

        if (is_array($jdata)) {
            $home_id = $this->ReadPropertyString('home_id');
            $product_id = $this->ReadPropertyString('product_id');

            $homes = $jdata['body']['homes'];
            foreach ($homes as $home) {
                $this->SendDebug(__FUNCTION__, 'home=' . print_r($home, true), 0);
                if (!isset($home['id'])) {
                    continue;
                }
                $home_id = $home['id'];
                $home_name = $this->GetArrayElem($home, 'name', 'ID:' . $home_id);
                if (isset($home['persons'])) {
                    $persons = $home['persons'];
                    if (is_array($persons)) {
                        foreach ($persons as $person) {
                            $this->SendDebug(__FUNCTION__, 'person=' . print_r($person, true), 0);

                            $person_id = $person['id'];
                            $pseudo = $this->GetArrayElem($person, 'pseudo', '');

                            $instanceID = 0;
                            foreach ($instIDs as $instID) {
                                $persID = IPS_GetProperty($instID, 'person_id');
                                if ($persID == $person_id) {
                                    $instanceID = $instID;
                                    break;
                                }
                            }

                            if ($instanceID && IPS_GetInstance($instanceID)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                                continue;
                            }

                            $entry = [
                                'instanceID' => $instanceID,
                                'name'       => $pseudo,
                                'home'       => $home_name,
                                'person_id'  => $person_id,
                                'create'     => [
                                    'moduleID'       => $guid,
                                    'location'       => $this->GetConfiguratorLocation($catID),
                                    'info'           => $home_name . '\\' . $pseudo,
                                    'configuration'  => [
                                        'pseudo'     => $pseudo,
                                        'person_id'  => $person_id,
                                        'home_id'    => $home_id,
                                    ]
                                ]
                            ];
                            $entries[] = $entry;
                        }
                    }
                }
            }
        }
        foreach ($instIDs as $instID) {
            $fnd = false;
            foreach ($entries as $entry) {
                if ($entry['instanceID'] == $instID) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd) {
                continue;
            }

            if (IPS_GetInstance($instID)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                continue;
            }

            $name = IPS_GetProperty($instID, 'pseudo');
            $home = '';
            $person_id = IPS_GetProperty($instID, 'person_id');

            $entry = [
                'instanceID' => $instID,
                'name'       => $name,
                'home'       => $home,
                'person_id'  => $person_id,
            ];

            $entries[] = $entry;
            $this->SendDebug(__FUNCTION__, 'missing entry=' . print_r($entry, true), 0);
        }

        return $entries;
    }

    private function GetConfigurator4Person()
    {
        $entries = $this->getConfiguratorValues();
        if (count($entries) > 0) {
            $configurator = [
                'type'    => 'Configurator',
                'name'    => 'persons',
                'caption' => 'Persons',

                'rowCount' => count($entries),

                'add'    => false,
                'delete' => false,
                'sort'   => [
                    'column'    => 'name',
                    'direction' => 'ascending'
                ],
                'columns' => [
                    [
                        'caption' => 'Home',
                        'name'    => 'home',
                        'width'   => '200px'
                    ],
                    [
                        'caption' => 'Pseudonym',
                        'name'    => 'name',
                        'width'   => 'auto'
                    ],
                    [
                        'caption' => 'Id',
                        'name'    => 'person_id',
                        'width'   => '200px'
                    ]
                ],
                'values' => $entries,
            ];
        } else {
            $configurator = false;
        }

        return $configurator;
    }

    private function GetFormElements()
    {
        $product_type = $this->ReadPropertyString('product_type');
        switch ($product_type) {
            case 'NACamera':
                $product_type_s = 'Netatmo Indoor camera (Welcome)';
                break;
            case 'NOC':
                $product_type_s = 'Netatmo Outdoor camera (Presence)';
                break;
            default:
                $product_type_s = 'Netatmo Camera';
                break;
        }
        $formElements = $this->GetCommonFormElements($product_type_s);

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'ValidationTextBox',
                    'enabled' => false,
                    'name'    => 'product_type',
                    'caption' => 'Product-Type'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'enabled' => false,
                    'name'    => 'product_id',
                    'caption' => 'Product-ID'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'enabled' => false,
                    'name'    => 'home_id',
                    'caption' => 'Home-ID'
                ],
            ],
            'caption' => 'Basic configuration (don\'t change)',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'CheckBox',
                    'name'    => 'with_last_contact',
                    'caption' => 'last communication with Netatmo'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'with_last_event',
                    'caption' => 'last event from Netatmo'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'with_last_notification',
                    'caption' => 'last notification from Netatmo'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'with_wifi_strength',
                    'caption' => 'Strength of wifi-signal'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'with_motion_detection',
                    'caption' => 'Motion detection'
                ],
            ],
            'caption' => 'optional data'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'hook',
                    'caption' => 'Webhook'
                ],
                [
                    'type'    => 'SelectScript',
                    'name'    => 'webhook_script',
                    'caption' => 'Adjustment of the returned HTML code'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Access to the IPS server'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'ipsIP',
                    'caption' => 'IP-Address'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'ipsPort',
                    'caption' => 'Port-Number'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Check whether the retrieval is from the local network'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'externalIP',
                    'caption' => 'external IP-Address'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'localCIDRs',
                    'caption' => 'local CIDR\'s'
                ],
                [
                    'type'    => 'SelectScript',
                    'name'    => 'url_changed_script',
                    'caption' => 'Call with changed VPN-URL'
                ],
            ],
            'caption' => 'Webhook'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'event_max_age',
                    'caption' => 'maximum age until deletion',
                    'minimum' => 0,
                    'suffix'  => 'days'
                ],
                [
                    'type'    => 'SelectScript',
                    'name'    => 'new_event_script',
                    'caption' => 'Call upon receipt of new events'
                ],
            ],
            'caption' => 'Events'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'notification_max_age',
                    'caption' => 'maximum age until deletion',
                    'minimum' => 0,
                    'suffix'  => 'days'
                ],
                [
                    'type'    => 'SelectScript',
                    'name'    => 'notify_script',
                    'caption' => 'Call upon receipt of a notification'
                ],
            ],
            'caption' => 'Notifications'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'Label',
                    'caption' => 'Local copy of videos from Netatmo via FTP'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'ftp_path',
                    'caption' => 'Path'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'ftp_max_age',
                    'caption' => 'maximum age until deletion',
                    'minimum' => 0,
                    'suffix'  => 'days'
                ],
            ],
            'caption' => 'FTP'
        ];

        if ($product_type == 'NOC') {
            $formElements[] = [
                'type'    => 'ExpansionPanel',
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => 'Local copy of Netatmo-Timelapse'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'timelapse_path',
                        'caption' => 'Path'
                    ],
                    [
                        'type'    => 'NumberSpinner',
                        'name'    => 'timelapse_hour',
                        'caption' => 'Startime',
                        'minimum' => 0,
                        'maximum' => 23,
                        'suffix'  => 'hour of day'
                    ],
                    [
                        'type'    => 'NumberSpinner',
                        'name'    => 'timelapse_max_age',
                        'caption' => 'maximum age until deletion',
                        'minimum' => 0,
                        'suffix'  => 'days'
                    ],
                ],
                'caption' => 'Timelapse'
            ];
        }

        if ($product_type == 'NACamera') {
            $entries = $this->getConfiguratorValues();
            if (count($entries) > 0) {
                $formElements[] = [
                    'type'    => 'ExpansionPanel',
                    'items'   => [
                        [
                            'type'    => 'SelectCategory',
                            'name'    => 'ImportCategoryID',
                            'caption' => 'category for persons to be created'
                        ],
                        [
                            'type'    => 'Configurator',
                            'name'    => 'persons',
                            'caption' => 'Persons',

                            'rowCount' => count($entries),

                            'add'    => false,
                            'delete' => false,
                            'sort'   => [
                                'column'    => 'name',
                                'direction' => 'ascending'
                            ],
                            'columns' => [
                                [
                                    'caption' => 'Home',
                                    'name'    => 'home',
                                    'width'   => '200px'
                                ],
                                [
                                    'caption' => 'Pseudonym',
                                    'name'    => 'name',
                                    'width'   => 'auto'
                                ],
                                [
                                    'caption' => 'Id',
                                    'name'    => 'person_id',
                                    'width'   => '200px'
                                ]
                            ],
                            'values' => $entries,
                        ],
                    ],
                    'caption' => 'Persons'
                ];
            }
        }

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

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
                [
                    'type'     => 'List',
                    'columns'  => [
                        [
                            'name'     => 'type',
                            'width'    => '100px',
                            'caption'  => 'Type',
                        ],
                        [
                            'name'     => 'value',
                            'width'    => 'auto',
                            'caption'  => 'Value',
                        ],
                    ],
                    'add'      => false,
                    'delete'   => false,
                    'rowCount' => 2,
                    'values'   => [
                        [
                            'type'  => 'vpn_url',
                            'value' => $this->GetBuffer('vpn_url'),
                        ],
                        [
                            'type'  => 'local_url',
                            'value' => $this->GetBuffer('local_url'),
                        ],
                    ],
                    'caption'  => 'internal informations',
                ],
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Test area',
            'expanded'  => false,
            'items'     => [
                [
                    'type'    => 'TestCenter',
                ],
            ]
        ];

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
        $product_id = $this->ReadPropertyString('product_id');

        $product_type = $this->ReadPropertyString('product_type');
        switch ($product_type) {
            case 'NACamera':
                $with_light = false;
                $with_power = true;
                break;
            case 'NOC':
                $with_light = true;
                $with_power = false;
                break;
            default:
                $with_light = false;
                $with_power = false;
                break;
        }
        $with_wifi_strength = $this->ReadPropertyBoolean('with_wifi_strength');
        $with_motion_detection = $this->ReadPropertyBoolean('with_motion_detection');

        $event_max_age = $this->ReadPropertyInteger('event_max_age');
        $notification_max_age = $this->ReadPropertyInteger('notification_max_age');

        $now = time();
        $camera_ok = true;
        $sd_ok = true;
        $power_ok = true;

        $this->SendDebug(__FUNCTION__, 'source=' . $source, 0);

        if ($buf != '') {
            $jdata = json_decode($buf, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

            switch ($source) {
                case 'QUERY':
                    $url_changed = false;
                    $homes = $this->GetArrayElem($jdata, 'body.homes', '');
                    if ($homes != '') {
                        foreach ($homes as $home) {
                            if ($home_id != $home['id']) {
                                continue;
                            }
                            $cameras = $this->GetArrayElem($home, 'cameras', '');
                            if ($cameras != '') {
                                foreach ($cameras as $camera) {
                                    if ($product_id != $camera['id']) {
                                        continue;
                                    }
                                    $this->SendDebug(__FUNCTION__, 'decode camera=' . print_r($camera, true), 0);

                                    $vpn_url = $this->GetArrayElem($camera, 'vpn_url', '');
                                    if ($vpn_url != $this->GetBuffer('vpn_url')) {
                                        $url_changed = true;
                                        $this->SetBuffer('vpn_url', $vpn_url);
                                        $this->SetBuffer('local_url', '');
                                    }

                                    $is_local = (bool) $this->GetArrayElem($camera, 'is_local', true);
                                    if ($is_local != $this->GetBuffer('is_local')) {
                                        $url_changed = true;
                                        $this->SetBuffer('is_local', $is_local);
                                        $this->SetBuffer('local_url', '');
                                    }

                                    $camera_status = $this->map_camera_status($this->GetArrayElem($camera, 'status', ''));
                                    if (is_int($camera_status)) {
                                        if ($camera_status != self::$CAMERA_STATUS_ON && $camera_status != self::$CAMERA_STATUS_OFF) {
                                            $camera_ok = false;
                                        }
                                        $this->SetValue('CameraStatus', $camera_status);
                                        if ($camera_status == self::$CAMERA_STATUS_ON) {
                                            $v = self::$CAMERA_STATUS_OFF;
                                        } else {
                                            $v = self::$CAMERA_STATUS_ON;
                                        }
                                        $this->SetValue('CameraAction', $v);
                                    }

                                    $sd_status = $this->map_sd_status($this->GetArrayElem($camera, 'sd_status', ''));
                                    if (is_int($sd_status)) {
                                        if ($sd_status != self::$SDCARD_STATUS_READY) {
                                            $sd_ok = false;
                                        }
                                        $this->SetValue('SDCardStatus', $sd_status);
                                    }

                                    if ($with_power) {
                                        $power_status = $this->map_power_status($this->GetArrayElem($camera, 'alim_status', ''));
                                        if (is_int($power_status)) {
                                            if ($power_status != self::$POWER_STATUS_GOOD) {
                                                $power_ok = false;
                                            }
                                            $this->SetValue('PowerStatus', $power_status);
                                        }
                                    }

                                    if ($with_light) {
                                        $s = $this->GetArrayElem($camera, 'light_mode_status', '');
                                        if ($s != '') {
                                            $light_mode_status = $this->map_lightmode_status($s);
                                            if (is_int($light_mode_status)) {
                                                $this->SetValue('LightmodeStatus', $light_mode_status);
                                                if ($light_mode_status == self::$LIGHT_STATUS_ON) {
                                                    $v = self::$LIGHT_STATUS_OFF;
                                                } else {
                                                    $v = self::$LIGHT_STATUS_ON;
                                                }
                                                $this->SetValue('LightAction', $v);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $ref_ts = $now - ($event_max_age * 24 * 60 * 60);

                    $cur_events = [];
                    $new_events = [];
                    $s = $this->GetMediaData('Events');
                    $prev_events = json_decode((string) $s, true);
                    $this->SendDebug(__FUNCTION__, 'prev_events=' . print_r($prev_events, true), 0);
                    $events = $this->GetArrayElem($home, 'events', '');
                    if ($events != '') {
                        $this->SendDebug(__FUNCTION__, 'n_events=' . count($events), 0);
                        foreach ($events as $event) {
                            if ($product_id != $event['camera_id']) {
                                continue;
                            }
                            $this->SendDebug(__FUNCTION__, 'decode event=' . print_r($event, true), 0);

                            $event_id = $this->GetArrayElem($event, 'id', '');

                            if (isset($event['time'])) {
                                $tstamp = $event['time'];
                            } else {
                                $tstamp = $this->GetArrayElem($event, 'event_list.0.time', 0);
                            }

                            $new_event = [
                                'id'          => $event_id,
                                'tstamp'      => $tstamp,
                            ];

                            $video_id = $this->GetArrayElem($event, 'video_id', '');
                            if ($video_id != '') {
                                $new_event['video_id'] = $video_id;
                            }

                            $person_id = $this->GetArrayElem($event, 'person_id', '');
                            if ($person_id != '') {
                                $new_event['person_id'] = $person_id;
                            }

                            $message = $this->GetArrayElem($event, 'message', '');
                            if ($message != '') {
                                $new_event['message'] = $message;
                            }

                            $video_status = $this->GetArrayElem($event, 'video_status', '');
                            if ($video_status != '') {
                                $new_event['video_status'] = $video_status;
                            }

                            $is_arrival = $this->GetArrayElem($event, 'is_arrival', '');
                            if ($is_arrival != '') {
                                $new_event['is_arrival'] = $is_arrival;
                            }

                            $type = $this->GetArrayElem($event, 'type', '');
                            if ($type == 'sd') {
                                $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                $type = ($sub_type == 4) ? 'sd_ok' : 'sd_nok';
                            }
                            if ($type == 'siren_sounding') {
                                $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                $type = ($sub_type == 1) ? 'siren_sounding' : 'siren_stopped';
                            }
                            if ($type != '') {
                                $new_event['event_type'] = $type;
                            }

                            $snapshot_id = $this->GetArrayElem($event, 'snapshot.id', '');
                            $snapshot_key = $this->GetArrayElem($event, 'snapshot.key', '');
                            $snapshot_filename = $this->GetArrayElem($event, 'snapshot.filename', '');
                            $snapshot = [];
                            if ($snapshot_id != '' && $snapshot_key != '') {
                                $snapshot['id'] = $snapshot_id;
                                $snapshot['key'] = $snapshot_key;
                            }
                            if ($snapshot_filename != '') {
                                $snapshot['filename'] = $snapshot_filename;
                            }
                            if ($snapshot != []) {
                                $new_event['snapshot'] = $snapshot;
                            }

                            $vignette_id = $this->GetArrayElem($event, 'vignette.id', '');
                            $vignette_key = $this->GetArrayElem($event, 'vignette.key', '');
                            $vignette_filename = $this->GetArrayElem($event, 'vignette.filename', '');
                            $vignette = [];
                            if ($vignette_id != '' && $vignette_key != '') {
                                $vignette['id'] = $vignette_id;
                                $vignette['key'] = $vignette_key;
                            }
                            if ($vignette_filename != '') {
                                $vignette['filename'] = $vignette_filename;
                            }
                            if ($vignette != []) {
                                $new_event['vignette'] = $vignette;
                            }

                            $new_subevents = [];
                            $subevents = $this->GetArrayElem($event, 'event_list', '');
                            if ($subevents != '') {
                                foreach ($subevents as $subevent) {
                                    $event_id = $this->GetArrayElem($subevent, 'id', '');
                                    $type = $this->GetArrayElem($subevent, 'type', '');
                                    $ts = $this->GetArrayElem($subevent, 'time', 0);
                                    $message = $this->GetArrayElem($subevent, 'message', '');

                                    $new_subevent = [
                                        'id'         => $event_id,
                                        'tstamp'     => $ts,
                                        'event_type' => $type,
                                        'message'    => $message,
                                    ];

                                    $snapshot_id = $this->GetArrayElem($subevent, 'snapshot.id', '');
                                    $snapshot_key = $this->GetArrayElem($subevent, 'snapshot.key', '');
                                    $snapshot_filename = $this->GetArrayElem($subevent, 'snapshot.filename', '');
                                    $snapshot = [];
                                    if ($snapshot_id != '' && $snapshot_key != '') {
                                        $snapshot['id'] = $snapshot_id;
                                        $snapshot['key'] = $snapshot_key;
                                    }
                                    if ($snapshot_filename != '') {
                                        $snapshot['filename'] = $snapshot_filename;
                                    }
                                    if ($snapshot != []) {
                                        $new_subevent['snapshot'] = $snapshot;
                                    }

                                    $vignette_id = $this->GetArrayElem($subevent, 'vignette.id', '');
                                    $vignette_key = $this->GetArrayElem($subevent, 'vignette.key', '');
                                    $vignette_filename = $this->GetArrayElem($subevent, 'vignette.filename', '');
                                    $vignette = [];
                                    if ($vignette_id != '' && $vignette_key != '') {
                                        $vignette['id'] = $vignette_id;
                                        $vignette['key'] = $vignette_key;
                                    }
                                    if ($vignette_filename != '') {
                                        $vignette['filename'] = $vignette_filename;
                                    }
                                    if ($vignette != []) {
                                        $new_subevent['vignette'] = $vignette;
                                    }

                                    $new_subevents[] = $new_subevent;
                                }
                                $new_event['subevents'] = $new_subevents;
                            }

                            // $this->SendDebug(__FUNCTION__, 'new_event=' . print_r($new_event, true), 0);
                            $cur_events[] = $new_event;

                            $fnd = false;
                            if ($prev_events != '') {
                                foreach ($prev_events as $prev_event) {
                                    if ($prev_event['id'] == $new_event['id']) {
                                        $fnd = true;
                                        break;
                                    }
                                }
                            }
                            if ($fnd == false) {
                                $new_events[] = $new_event;
                            }
                        }
                    }

                    $n_new_events = count($new_events);
                    $first_new_ts = false;
                    if ($cur_events != []) {
                        usort($cur_events, ['NetatmoSecurityCamera', 'cmp_events']);
                        $first_new_ts = $cur_events[0]['tstamp'];
                        $this->SendDebug(__FUNCTION__, 'found events: new=' . $n_new_events . ', total=' . count($cur_events) . ', first=' . date('d.m.Y H:i:s', $first_new_ts), 0);
                    }
                    $n_chg_events = 0;
                    $n_del_events = 0;
                    if ($prev_events != '') {
                        foreach ($prev_events as $prev_event) {
                            if ($prev_event['tstamp'] < $ref_ts) {
                                $n_del_events++;
                                $this->SendDebug(__FUNCTION__, 'delete id=' . $prev_event['id'] . ', ts=' . date('d.m.Y H:i:s', $prev_event['tstamp']), 0);
                                continue;
                            }
                            $fnd = false;
                            if ($cur_events != []) {
                                foreach ($cur_events as $new_event) {
                                    if ($new_event['id'] == $prev_event['id']) {
                                        $fnd = true;
                                        if (json_encode($new_event) != json_encode($prev_event)) {
                                            $n_chg_events++;
                                        }
                                        break;
                                    }
                                }
                            }
                            if ($fnd) {
                                continue;
                            }
                            if ($first_new_ts && $prev_event['tstamp'] > $first_new_ts && !isset($prev_event['deleted'])) {
                                $this->SendDebug(__FUNCTION__, 'mark as deleted: id=' . $prev_event['id'] . ', ts=' . date('d.m.Y H:i:s', $prev_event['tstamp']), 0);
                                $prev_event['deleted'] = true;
                                $n_chg_events++;
                            }
                            $cur_events[] = $prev_event;
                        }
                        $this->SendDebug(__FUNCTION__, 'cleanup events: changed=' . $n_chg_events . ', deleted=' . $n_del_events, 0);
                    }

                    if ($cur_events != []) {
                        usort($cur_events, ['NetatmoSecurityCamera', 'cmp_events']);
                        $s = json_encode($cur_events);
                    } else {
                        $s = '';
                    }
                    $this->SetMediaData('Events', $s, MEDIATYPE_DOCUMENT, '.dat', false);

                    $with_last_event = $this->ReadPropertyBoolean('with_last_event');
                    if ($with_last_event && $n_new_events > 0) {
                        $this->SetValue('LastEvent', $now);
                    }

                    $system_ok = $this->GetArrayElem($jdata, 'status', '') == 'ok' ? true : false;
                    $status = $system_ok && $camera_ok && $sd_ok && $power_ok;
                    $this->SendDebug(__FUNCTION__, 'states: system=' . $this->bool2str($system_ok) . ', camera=' . $this->bool2str($camera_ok) . ', sd=' . $this->bool2str($sd_ok) . ', power=' . $this->bool2str($power_ok) . ' => ' . $this->bool2str($status), 0);
                    $this->SetValue('Status', $status);

                    $with_last_contact = $this->ReadPropertyBoolean('with_last_contact');
                    if ($with_last_contact) {
                        $tstamp = $this->GetArrayElem($jdata, 'time_server', 0);
                        $this->SetValue('LastContact', $tstamp);
                    }

                    if ($n_new_events > 0 || $n_chg_events > 0 || $n_del_events > 0) {
                        $new_event_script = $this->ReadPropertyInteger('new_event_script');
                        if (IPS_ScriptExists($new_event_script)) {
                            $opts = [
                                'InstanceID'  => $this->InstanceID,
                                'new_events'  => json_encode($new_events)
                            ];
                            $r = IPS_RunScriptWaitEx($new_event_script, $opts);
                            $this->SendDebug(__FUNCTION__, 'new_event_script=' . IPS_GetName($new_event_script) . ', ret=' . $r, 0);
                        }
                    }

                    if ($url_changed) {
                        $url_changed_script = $this->ReadPropertyInteger('url_changed_script');
                        if (IPS_ScriptExists($url_changed_script)) {
                            $r = IPS_RunScriptWaitEx($url_changed_script, ['InstanceID' => $this->InstanceID]);
                            $this->SendDebug(__FUNCTION__, 'url_changed_script=' . IPS_GetName($url_changed_script) . ', ret=' . $r, 0);
                        }
                    }

                    if ($with_light) {
                        $this->GetLightConfig();
                    }
                    if ($with_wifi_strength) {
                        $this->GetHomeStatus();
                    }

                    break;
                case 'EVENT':
                    $ref_ts = $now - ($notification_max_age * 24 * 60 * 60);
                    $notification = $jdata;

                    $new_notifications = [];
                    $cur_notifications = [];
                    $s = $this->GetMediaData('Notifications');
                    $prev_notifications = json_decode((string) $s, true);
                    if ($prev_notifications != '') {
                        foreach ($prev_notifications as $prev_notification) {
                            if ($prev_notification['tstamp'] < $ref_ts) {
                                continue;
                            }
                            $cur_notifications[] = $prev_notification;
                        }
                    }

                    $camera_id = $this->GetArrayElem($notification, 'camera_id', '');
                    if ($camera_id == '' || $product_id == $camera_id) {
                        $this->SendDebug(__FUNCTION__, 'decode notification=' . print_r($notification, true), 0);

                        $push_type = $this->GetArrayElem($notification, 'push_type', '');

                        $event_id = $this->GetArrayElem($notification, 'event_id', '');
                        $event_type = $this->GetArrayElem($notification, 'event_type', '');
                        $sub_type = $this->GetArrayElem($notification, 'sub_type', '');
                        $message = $this->GetArrayElem($notification, 'message', '');

                        $event_id = $this->GetArrayElem($notification, 'event_id', '');
                        $event_type = $this->GetArrayElem($notification, 'event_type', '');
                        $sub_type = $this->GetArrayElem($notification, 'sub_type', '');
                        $message = $this->GetArrayElem($notification, 'message', '');

                        switch ($push_type) {
                            case 'NOC-movement':
                            case 'NOC-human':
                            case 'NOC-animal':
                            case 'NOC-vehicle':
                                switch ($push_type) {
                                    case 'NOC-movement':
                                        $message = $this->Translate('Movement detected');
                                        $motion_type = self::$MOTION_TYPE_MOVEMENT;
                                        break;
                                    case 'NOC-human':
                                        $message = $this->Translate('Person captured');
                                        $motion_type = self::$MOTION_TYPE_HUMAN;
                                        break;
                                    case 'NOC-animal':
                                        $message = $this->Translate('Animal captured');
                                        $motion_type = self::$MOTION_TYPE_ANIMAL;
                                        break;
                                    case 'NOC-vehicle':
                                        $message = $this->Translate('Vehicle captured');
                                        $motion_type = self::$MOTION_TYPE_VEHICLE;
                                        break;
                                    default:
                                        if ($message == '') {
                                            $message = $event_type . '-' . $sub_type;
                                        }
                                        $motion_type = self::$MOTION_TYPE_NONE;
                                        break;
                                }
                                if ($with_motion_detection && $motion_type != self::$MOTION_TYPE_NONE) {
                                    $this->SetValue('MotionType', $motion_type);
                                    $this->MaintainTimer('MotionRelease', self::$MOTION_RELEASE * 1000);
                                }

                                $cur_notification = [
                                    'tstamp'       => $now,
                                    'id'           => $event_id,
                                    'push_type'    => $push_type,
                                    'event_type'   => $event_type,
                                    'message'      => $message,
                                ];

                                $subevent_id = $this->GetArrayElem($notification, 'subevent_id', '');
                                if ($subevent_id != '') {
                                    $cur_notification['subevent_id'] = $subevent_id;
                                }

                                $snapshot_id = $this->GetArrayElem($notification, 'snapshot_id', '');
                                $snapshot_key = $this->GetArrayElem($notification, 'snapshot_key', '');
                                if ($snapshot_id != '' && $snapshot_key != '') {
                                    $snapshot = [
                                        'id'  => $snapshot_id,
                                        'key' => $snapshot_key,
                                    ];
                                    $cur_notification['snapshot'] = $snapshot;
                                }

                                $vignette_id = $this->GetArrayElem($notification, 'vignette_id', '');
                                $vignette_key = $this->GetArrayElem($notification, 'vignette_key', '');
                                if ($vignette_id != '' && $vignette_key != '') {
                                    $vignette = [
                                        'id'  => $vignette_id,
                                        'key' => $vignette_key,
                                    ];
                                    $cur_notification['vignette'] = $vignette;
                                }

                                $this->SendDebug(__FUNCTION__, 'push_type=' . $push_type . ', event_type=' . $event_type . ', sub_type=' . $sub_type . ' => ' . print_r($cur_notification, true), 0);

                                $cur_notifications[] = $cur_notification;
                                $new_notifications[] = $cur_notification;
                                break;
                            case 'NACamera-movement':
                            case 'NACamera-person':
                            case 'NACamera-human':
                            case 'NACamera-animal':
                                switch ($push_type) {
                                    case 'NACamera-movement':
                                        $message = $this->Translate('Movement detected');
                                        $motion_type = self::$MOTION_TYPE_MOVEMENT;
                                        break;
                                    case 'NACamera-person':
                                    case 'NACamera-human':
                                        $message = $this->Translate('Person captured');
                                        $is_known = false;
                                        if (isset($notification['persons'])) {
                                            foreach ($notification['persons'] as $person) {
                                                $is_known = (bool) $this->GetArrayElem($person, 'is_known', false);
                                                if ($is_known) {
                                                    break;
                                                }
                                            }
                                        }
                                        $motion_type = $is_known ? self::$MOTION_TYPE_PERSON : self::$MOTION_TYPE_HUMAN;
                                        break;
                                    case 'NACamera-animal':
                                        $message = $this->Translate('Animal captured');
                                        $motion_type = self::$MOTION_TYPE_ANIMAL;
                                        break;
                                    default:
                                        if ($message == '') {
                                            $message = $event_type . '-' . $sub_type;
                                        }
                                        $motion_type = self::$MOTION_TYPE_NONE;
                                        break;
                                }
                                if ($with_motion_detection && $motion_type != self::$MOTION_TYPE_NONE) {
                                    $this->SetValue('MotionType', $motion_type);
                                    $this->MaintainTimer('MotionRelease', self::$MOTION_RELEASE * 1000);
                                }

                                $cur_notification = [
                                    'tstamp'       => $now,
                                    'id'           => $event_id,
                                    'push_type'    => $push_type,
                                    'event_type'   => $event_type,
                                    'message'      => $message,
                                ];

                                $snapshot_id = $this->GetArrayElem($notification, 'snapshot_id', '');
                                $snapshot_key = $this->GetArrayElem($notification, 'snapshot_key', '');
                                if ($snapshot_id != '' && $snapshot_key != '') {
                                    $snapshot = [
                                        'id'  => $snapshot_id,
                                        'key' => $snapshot_key,
                                    ];
                                    $cur_notification['snapshot'] = $snapshot;
                                }

                                $vignette_id = $this->GetArrayElem($notification, 'vignette_id', '');
                                $vignette_key = $this->GetArrayElem($notification, 'vignette_key', '');
                                if ($vignette_id != '' && $vignette_key != '') {
                                    $vignette = [
                                        'id'  => $vignette_id,
                                        'key' => $vignette_key,
                                    ];
                                    $cur_notification['vignette'] = $vignette;
                                }

                                if (isset($notification['persons'])) {
                                    $cur_persons = [];
                                    $persons = $notification['persons'];
                                    foreach ($persons as $person) {
                                        $person_id = $this->GetArrayElem($person, 'id', '');
                                        $cur_person = [
                                            'person_id' => $person_id,
                                        ];

                                        $is_known = $this->GetArrayElem($person, 'is_known', '');
                                        if ($is_known != '') {
                                            $cur_person['is_known'] = $is_known;
                                        }

                                        $face_url = $this->GetArrayElem($person, 'face_url', '');
                                        if ($face_url != '') {
                                            $cur_person['face_url'] = $face_url;
                                        }

                                        $cur_persons[] = $cur_person;
                                    }
                                    if ($cur_persons != []) {
                                        $cur_notification['persons'] = $cur_persons;
                                    }
                                }

                                $this->SendDebug(__FUNCTION__, 'push_type=' . $push_type . ', event_type=' . $event_type . ', sub_type=' . $sub_type . ' => ' . print_r($cur_notification, true), 0);

                                $cur_notifications[] = $cur_notification;
                                $new_notifications[] = $cur_notification;
                                break;

case 'NSD-hush': // When the smoke detection is activated or deactivated (0=hush, 1=ready)
case 'NSD-smoke': // When smoke is detected or smoke is cleared (0=cleared, 1=detected)
case 'NSD-tampered': // When smoke detector is ready or tampered (0=ready, 1=tampered)
case 'NSD-wifi_status': // When wifi status is updated (0=error, 1=ok)
case 'NSD-battery_status': // When battery status is too low (0=low, 1=very low)
case 'NSD-detection_chamber_status': // When the detection chamber is dusty or clean (0=clean, 1=dusty)
case 'NSD-sound_test': // Sound test result
    break;

case 'NCO-co_detected': // When carbon monoxide is detected (0=ok, 1=pre-alarm, 2=alarm)
case 'NCO-wifi_status': // When wifi status is updated (0=error, 1=ok)
case 'NCO-battery_status': // When battery status is too low (0=low, 1=very low)
case 'NCO-sound_test': // Sound test result
    break;

                            case 'connection':
                            case 'disconnection':
                            case 'NACamera-alarm_started':
                            case 'NACamera-connection':
                            case 'NACamera-disconnection':
                            case 'NACamera-off':
                            case 'NACamera-on':
                            case 'NOC-connection':
                            case 'NOC-disconnection':
                            case 'NOC-ftp':
                            case 'NOC-light_mode':
                            case 'NOC-off':
                            case 'NOC-on':
                            case 'off':
                            case 'on':
                                switch ($push_type) {
                                    case 'connection':
                                    case 'NACamera-connection':
                                    case 'NOC-connection':
                                        $message = $this->Translate('Camera connected');
                                        break;
                                    case 'disconnection':
                                    case 'NACamera-disconnection':
                                    case 'NOC-disconnection':
                                        $message = $this->Translate('Camera disconnected');
                                        break;
                                    case 'NOC-light_mode':
                                        $message = $this->Translate('Light-mode changed to ' . $sub_type);
                                        break;
                                    case 'on':
                                    case 'NACamera-on':
                                    case 'NOC-off':
                                        $message = $this->Translate('Monitoring disabled');
                                        break;
                                    case 'off':
                                    case 'NACamera-off':
                                    case 'NOC-on':
                                        $message = $this->Translate('Monitoring enabled');
                                        break;
                                    case 'NOC-ftp':
                                        switch ($message) {
                                            case 'ftp-0':
                                                $message = $this->Translate('Upload successful (FTP)');
                                                $event_type = 'ftp-ok';
                                                break;
                                            case 'ftp-3':
                                                $message = $this->Translate('Network error (FTP)');
                                                $event_type = 'ftp-nok';
                                                break;
                                            default:
                                                $event_type = 'ftp-nok';
                                                break;
                                        }
                                        break;
                                    case 'NACamera-alarm_started':
                                        $message = $this->Translate('Alarm detected');
                                        break;
                                    default:
                                        if ($message == '') {
                                            $message = $event_type . '-' . $sub_type;
                                        }
                                        break;
                                }

                                $cur_notification = [
                                    'tstamp'       => $now,
                                    'id'           => $event_id,
                                    'push_type'    => $push_type,
                                    'event_type'   => $event_type,
                                    'message'      => $message,
                                ];

                                $this->SendDebug(__FUNCTION__, 'push_type=' . $push_type . ', event_type=' . $event_type . ', sub_type=' . $sub_type . ' => ' . print_r($cur_notification, true), 0);

                                $cur_notifications[] = $cur_notification;
                                $new_notifications[] = $cur_notification;
                                break;
                            case 'alert':
                            case 'daily_summary':
                            case 'topology_changed':
                            case 'webhook_activation':
                                $err = 'ignore push_type "' . $push_type . '", data=' . print_r($notification, true);
                                $this->SendDebug(__FUNCTION__, $err, 0);
                                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_MESSAGE);
                                break;
                            default:
                                $err = 'unknown push_type "' . $push_type . '", data=' . print_r($notification, true);
                                $this->SendDebug(__FUNCTION__, $err, 0);
                                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
                                break;
                        }
                    }

                    if ($cur_notifications != []) {
                        usort($cur_notifications, ['NetatmoSecurityCamera', 'cmp_events']);
                        $s = json_encode($cur_notifications);
                    } else {
                        $s = '';
                    }
                    $this->SetMediaData('Notifications', $s, MEDIATYPE_DOCUMENT, '.dat', false);

                    $n_new_notifications = count($new_notifications);

                    if ($n_new_notifications > 0) {
                        $with_last_notification = $this->ReadPropertyBoolean('with_last_notification');
                        if ($with_last_notification) {
                            $this->SetValue('LastNotification', $now);
                        }
                        $notify_script = $this->ReadPropertyInteger('notify_script');
                        if (IPS_ScriptExists($notify_script)) {
                            $opts = [
                                'InstanceID'        => $this->InstanceID,
                                'new_notifications' => json_encode($new_notifications)
                            ];
                            $r = IPS_RunScriptWaitEx($notify_script, $opts);
                            $this->SendDebug(__FUNCTION__, 'notify_script=' . IPS_GetName($notify_script) . ', ret=' . $r, 0);
                        }
                    }
                    break;
                default:
                    $err = 'unknown source "' . $source . '"';
                    $this->SendDebug(__FUNCTION__, $err, 0);
                    $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
                    break;
            }
        }

        $this->MaintainStatus(IS_ACTIVE);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'doCleanupPath':
                $this->doCleanupPath();
                break;
            case 'doLoadTimelapse':
                $this->doLoadTimelapse();
                break;
            case 'doMotionRelease':
                $this->doMotionRelease();
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

        $product_type = $this->ReadPropertyString('product_type');

        switch ($ident) {
            case 'LightAction':
                if ($product_type == 'NOC') {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    $this->SwitchLight($value);
                } else {
                    $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident . ' for product ' . $product_type, 0);
                }
                break;
            case 'LightIntensity':
                if ($product_type == 'NOC') {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    if ($this->DimLight($value)) {
                        $this->SetValue('LightIntensity', $value);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident . ' for product ' . $product_type, 0);
                }
                break;
            case 'CameraAction':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $this->SwitchCamera($value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }

    public function SwitchLight(int $mode)
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            $this->LogMessage('has no active parent instance', KL_WARNING);
            return false;
        }

        $url = $this->determineUrl();
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        switch ($mode) {
            case self::$LIGHT_STATUS_OFF:
                $value = 'off';
                break;
            case self::$LIGHT_STATUS_ON:
                $value = 'on';
                break;
            case self::$LIGHT_STATUS_AUTO:
                $value = 'auto';
                break;
            default:
                $err = 'unknown mode "' . $mode . '"';
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
                return false;
        }
        $url .= '/command/floodlight_set_config?config=' . urlencode('{"mode":"' . $value . '"}');

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlGet', 'Url' => $url];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        return true;
    }

    public function DimLight(int $intensity)
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            $this->LogMessage('has no active parent instance', KL_WARNING);
            return false;
        }

        $product_type = $this->ReadPropertyString('product_type');
        if ($product_type != 'NOC') {
            $this->SendDebug(__FUNCTION__, 'not aviable for product ' . $product_type, 0);
            return false;
        }

        $url = $this->determineUrl();
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $intensity = intval($intensity);
        if ($intensity > 100 || $intensity < 0) {
            $err = 'linght-intensity range from 0 to 100';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url .= '/command/floodlight_set_config?config=' . urlencode('{"intensity":"' . $intensity . '"}');

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlGet', 'Url' => $url];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        return true;
    }

    private function GetLightConfig()
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            $this->LogMessage('has no active parent instance', KL_WARNING);
            return false;
        }

        $product_type = $this->ReadPropertyString('product_type');
        if ($product_type != 'NOC') {
            $this->SendDebug(__FUNCTION__, 'not aviable for product ' . $product_type, 0);
            return false;
        }

        $url = $this->determineUrl();
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url .= '/command/floodlight_get_config';

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlGet', 'Url' => $url];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        if ($jdata['status'] == 'ok') {
            $jdata = json_decode($jdata['data'], true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

            $intensity = $this->GetArrayElem($jdata, 'intensity', '');
            if ($intensity != '') {
                $this->SetValue('LightIntensity', $intensity);
            }
            $light_mode_status = $this->map_lightmode_status($this->GetArrayElem($jdata, 'mode', ''));
            if (is_int($light_mode_status)) {
                $this->SetValue('LightmodeStatus', $light_mode_status);
                if ($light_mode_status == self::$LIGHT_STATUS_ON) {
                    $v = self::$LIGHT_STATUS_OFF;
                } else {
                    $v = self::$LIGHT_STATUS_ON;
                }
                $this->SetValue('LightAction', $v);
            }
        }

        return true;
    }

    public function SwitchCamera(int $mode)
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            $this->LogMessage('has no active parent instance', KL_WARNING);
            return false;
        }

        $url = $this->determineUrl();
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        switch ($mode) {
            case self::$CAMERA_STATUS_OFF:
                $value = 'off';
                break;
            case self::$CAMERA_STATUS_ON:
                $value = 'on';
                break;
            default:
                $err = 'unknown mode "' . $mode . '"';
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
                return false;
        }

        $url .= '/command/changestatus?status=' . $value;

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlGet', 'Url' => $url];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        return true;
    }

    public function DeleteEvent(string $event_id)
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            $this->LogMessage('has no active parent instance', KL_WARNING);
            return false;
        }

        $product_id = $this->ReadPropertyString('product_id');
        $home_id = $this->ReadPropertyString('home_id');

        $event = false;
        $data = $this->GetEvents();
        $events = json_decode($data, true);
        foreach ($events as $e) {
            if ($event_id == $e['id']) {
                $event = $e;
                break;
            }
        }

        if ($event == false) {
            $this->SendDebug(__FUNCTION__, 'event_id ' . $event_id . ' not found', 0);
            return false;
        }
        if (isset($event['deleted']) && $event['deleted']) {
            $this->SendDebug(__FUNCTION__, 'event_id ' . $event_id . ' found but deleted', 0);
            return false;
        }

        $url = 'https://api.netatmo.com/api/deleteevent';

        $postdata = [
            'home_id'   => $home_id,
            'camera_id' => $product_id,
            'event_id'  => $event_id,
        ];
        $pdata = json_encode($postdata);

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlPostWithAuth', 'Url' => $url, 'PostData' => $pdata];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        $status = isset($jdata['status']) ? $jdata['status'] : 'error';
        $this->SendDebug(__FUNCTION__, 'event_id ' . $event_id . ' deleted => ' . $status, 0);

        if ($status == 'ok') {
            for ($i = 0; $i < count($events); $i++) {
                if ($events[$i]['id'] == $event_id) {
                    $events[$i]['deleted'] = true;
                    break;
                }
            }
            $this->SetMediaData('Events', json_encode($events), MEDIATYPE_DOCUMENT, '.dat', false);
        }

        return $status == 'ok';
    }

    private function GetHomeStatus()
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            $this->LogMessage('has no active parent instance', KL_WARNING);
            return false;
        }

        $home_id = $this->ReadPropertyString('home_id');
        $product_id = $this->ReadPropertyString('product_id');
        $with_wifi_strength = $this->ReadPropertyBoolean('with_wifi_strength');

        $url = 'https://app.netatmo.net/syncapi/v1/homestatus';

        $postdata = [
            'home_id'       => $home_id,
            'gateway_types' => ['NACamera', 'NOC', 'NDB', 'NSD', 'NCO'],
        ];
        $pdata = json_encode($postdata);

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlPostWithAuth', 'Url' => $url, 'PostData' => $pdata];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        if ($jdata['status'] == 'ok') {
            $jdata = json_decode($jdata['data'], true);

            $modules = $this->GetArrayElem($jdata, 'body.home.modules', '');
            if ($modules != '') {
                foreach ($modules as $module) {
                    if ($product_id != $module['id']) {
                        continue;
                    }
                    $this->SendDebug(__FUNCTION__, 'module=' . print_r($module, true), 0);

                    if ($with_wifi_strength) {
                        $wifi_strength = $this->map_wifi_strength($this->GetArrayElem($module, 'wifi_strength', ''));
                        $this->SendDebug(__FUNCTION__, 'wifi_strength=' . $wifi_strength, 0);
                        $this->SetValue('WifiStrength', $wifi_strength);
                    }
                }
            }
        }
    }

    private function GetHomeData()
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            $this->LogMessage('has no active parent instance', KL_WARNING);
            return false;
        }

        $home_id = $this->ReadPropertyString('home_id');
        $product_id = $this->ReadPropertyString('product_id');

        $url = 'https://app.netatmo.net/api/homesdata';

        $postdata = [
            'home_id'       => $home_id,
            'gateway_types' => ['NACamera', 'NOC', 'NDB', 'NSD', 'NCO'],
        ];
        $pdata = json_encode($postdata);

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlPostWithAuth', 'Url' => $url, 'PostData' => $pdata];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', got data=' . print_r($data, true), 0);

        $jdata = json_decode($data, true);
        if ($jdata['status'] == 'ok') {
            $jdata = json_decode($jdata['data'], true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

            $homes = $this->GetArrayElem($jdata, 'body.homes', '');
            $this->SendDebug(__FUNCTION__, 'homes=' . print_r($homes, true), 0);
            if ($homes != '') {
                foreach ($homes as $home) {
                    if ($home_id != $home['id']) {
                        continue;
                    }
                    $this->SendDebug(__FUNCTION__, 'home=' . print_r($home, true), 0);

                    $NOC = $this->GetArrayElem($home, 'NOC', '');
                    $this->SendDebug(__FUNCTION__, 'NOC=' . print_r($NOC, true), 0);
                }
            }
        }
    }

    private function cmp_events($a, $b)
    {
        $a_tstamp = $a['tstamp'];
        $b_tstamp = $b['tstamp'];
        if ($a_tstamp != $b_tstamp) {
            return ($a_tstamp < $b_tstamp) ? -1 : 1;
        }
        $a_id = $a['id'];
        $b_id = $b['id'];
        return (strcmp($a_id, $b_id) < 0) ? -1 : 1;
    }

    private function determineVpnUrl()
    {
        $vpn_url = $this->GetBuffer('vpn_url');
        $this->SendDebug(__FUNCTION__, 'vpn_url=' . $vpn_url, 0);
        return $vpn_url;
    }

    private function determineLocalUrl()
    {
        $is_local = $this->GetBuffer('is_local');
        $this->SendDebug(__FUNCTION__, 'is_local=' . $is_local, 0);
        if (!$is_local) {
            return false;
        }

        $local_url = $this->GetBuffer('local_url');
        $this->SendDebug(__FUNCTION__, 'local_url=' . $local_url, 0);
        if ($local_url != '') {
            return $local_url;
        }

        $vpn_url = $this->GetBuffer('vpn_url');
        $this->SendDebug(__FUNCTION__, 'vpn_url=' . $vpn_url, 0);
        if ($vpn_url == '') {
            return false;
        }

        $url = $vpn_url . '/command/ping';
        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlGet', 'Url' => $url];
        $data = $this->SendDataToParent(json_encode($SendData));
        if ($data != '') {
            $jdata = json_decode($data, true);
            $response = json_decode($jdata['data'], true);
            $this->SendDebug(__FUNCTION__, 'vpn response=' . print_r($response, true), 0);
            $local_url1 = $this->GetArrayElem($response, 'local_url', '');

            $url = $local_url1 . '/command/ping';
            $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlGet', 'Url' => $url];
            $data = $this->SendDataToParent(json_encode($SendData));
            if ($data != '') {
                $jdata = json_decode($data, true);
                $response = json_decode($jdata['data'], true);
                $this->SendDebug(__FUNCTION__, 'local response=' . print_r($response, true), 0);
                $local_url2 = $this->GetArrayElem($response, 'local_url', '');
                if ($local_url1 == $local_url2) {
                    $local_url = $local_url1;
                }
            }
        }

        $this->SetBuffer('local_url', $local_url);

        return $local_url;
    }

    private function determineUrl()
    {
        $url = $this->determineLocalUrl();
        if ($url == false) {
            $url = $this->determineVpnUrl();
        }
        return $url;
    }

    public function GetVpnUrl()
    {
        $url = $this->determineVpnUrl();
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function GetLocalUrl()
    {
        $url = $this->determineLocalUrl();
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function GetLiveVideoUrl(string $resolution, bool $preferLocal)
    {
        if (!in_array($resolution, ['poor', 'low', 'medium', 'high'])) {
            $err = 'unknown resolution "' . $resolution . '"';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url = $preferLocal ? $this->determineLocalUrl() : false;
        if ($url == false) {
            $url = $this->determineVpnUrl();
        }
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url .= '/live/files/' . $resolution . '/index.m3u8';
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function GetLiveSnapshotUrl(bool $preferLocal)
    {
        $url = $preferLocal ? $this->determineLocalUrl() : false;
        if ($url == false) {
            $url = $this->determineVpnUrl();
        }
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url .= '/live/snapshot_720.jpg';
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function GetVideoUrl(string $video_id, string $resolution, bool $preferLocal)
    {
        if (!in_array($resolution, ['poor', 'low', 'medium', 'high'])) {
            $err = 'unknown resolution "' . $resolution . '"';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url = $preferLocal ? $this->determineLocalUrl() : false;
        if ($url == false) {
            $url = $this->determineVpnUrl();
        }
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url .= '/vod/' . $video_id . '/files/' . $resolution . '/index.m3u8';
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function GetPictureUrl(string $id, string $key)
    {
        $url = 'https://api.netatmo.com/api/getcamerapicture?image_id=' . $id . '&key=' . $key;
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function GetPictureUrl4Filename(string $filename, bool $preferLocal)
    {
        $url = $preferLocal ? $this->determineLocalUrl() : false;
        if ($url == false) {
            $url = $this->determineVpnUrl();
        }
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url .= '/' . $filename;
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function GetEvents()
    {
        $data = $this->GetMediaData('Events');
        return $data;
    }

    public function GetNotifications()
    {
        $data = $this->GetMediaData('Notifications');
        return $data;
    }

    public function GetTimeline(bool $withDeleted)
    {
        $data = $this->GetEvents();
        $events = json_decode($data, true);
        if ($events == false) {
            $events = [];
        }

        $data = $this->GetNotifications();
        $notifications = json_decode($data, true);
        if ($notifications == false) {
            $notifications = [];
        }

        $n_events = count($events);
        $last_new_ts = $n_events > 0 ? $events[$n_events - 1]['tstamp'] : 0;

        $timeline = [];

        foreach ($events as $event) {
            $deleted = isset($event['deleted']) ? $event['deleted'] : false;
            if ($withDeleted == false && $deleted == true) {
                continue;
            }
            $event_types = [];
            if (isset($event['event_type'])) {
                $event_types[] = $event['event_type'];
            }
            if (isset($event['subevents'])) {
                $subevents = $event['subevents'];
                foreach ($subevents as $subevent) {
                    $event_type = $subevent['event_type'];
                    if (!in_array($event_type, $event_types)) {
                        $event_types[] = $event_type;
                    }
                }
            }
            $event['event_types'] = $event_types;
            $timeline[] = $event;
        }

        $n_add_notifications = 0;
        foreach ($notifications as $notification) {
            $id = $notification['id'];
            $tstamp = $notification['tstamp'];
            $event_type = isset($notification['event_type']) ? $notification['event_type'] : '';
            $subevent_id = isset($notification['subevent_id']) ? $notification['subevent_id'] : '';

            if ($id != '') {
                $tstamp = $notification['tstamp'];
                if ($tstamp < $last_new_ts) {
                    continue;
                }
            }

            $fnd = false;
            foreach ($events as $event) {
                if ($event['id'] == $id) {
                    $fnd = true;
                    break;
                }
                if ($event['tstamp'] == $tstamp && isset($event['event_type']) && $event['event_type'] == $event_type) {
                    $fnd = true;
                    break;
                }
                if (isset($event['subevents'])) {
                    foreach ($event['subevents'] as $subevent) {
                        if ($subevent['id'] == $subevent_id) {
                            $fnd = true;
                            break;
                        }
                    }
                }
                if ($fnd) {
                    break;
                }
            }
            if (!$fnd) {
                $timeline[] = $notification;
                $n_add_notifications++;
            }
        }

        if ($timeline != []) {
            usort($timeline, ['NetatmoSecurityCamera', 'cmp_events']);
        }

        $n_events = count($events);
        $n_timeline = count($timeline);

        $this->SendDebug(__FUNCTION__, 'n_timeline=' . $n_timeline . ' (events=' . $n_events . ', add notifications=' . $n_add_notifications . ')', 0);
        return json_encode($timeline);
    }

    public function MergeTimeline(string $total_timeline, string $add_timeline, string $tag)
    {
        $jtotal_timeline = json_decode($total_timeline, true);
        if ($jtotal_timeline == false) {
            $jtotal_timeline = [];
        }

        $jadd_timeline = json_decode($add_timeline, true);
        if ($jadd_timeline != false) {
            foreach ($jadd_timeline as $jt) {
                if (!isset($jt['tag'])) {
                    $jt['tag'] = $tag;
                }
                $jtotal_timeline[] = $jt;
            }
        }

        usort($jtotal_timeline, ['NetatmoSecurityCamera', 'cmp_events']);

        return json_encode($jtotal_timeline);
    }

    public function GetVideoFilename(string $video_id, int $tstamp)
    {
        $ftp_path = $this->ReadPropertyString('ftp_path');
        if ($ftp_path == '') {
            $this->SendDebug(__FUNCTION__, '"ftp_path" is not defined', 0);
            return false;
        }

        if ($video_id == '') {
            $this->SendDebug(__FUNCTION__, 'empty video_id "' . $video_id . '"', 0);
            return false;
        }
        $ids = explode('-', $video_id);
        if ($ids == false) {
            $this->SendDebug(__FUNCTION__, 'invalid video_id "' . $video_id . '"', 0);
            return false;
        }
        $id = $ids[0];

        $path = IPS_GetKernelDir() . $ftp_path;
        if (substr($path, -1) != DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
        }

        for ($i = 0, $ok = false; $i < 2 && !$ok; $i++) {
            $y = date('Y', $tstamp);
            $m = date('m', $tstamp);
            $d = date('d', $tstamp);
            $H = date('H', $tstamp);
            $M = date('i', $tstamp);

            $filename = $path;
            $filename .= $y . DIRECTORY_SEPARATOR . $m . DIRECTORY_SEPARATOR . $d . DIRECTORY_SEPARATOR;
            $filename .= $y . '-' . $m . '-' . $d . '_' . $H . '.' . $M . '_' . $id . '.mp4';

            $ok = is_file($filename);
            if (!$ok) {
                // der zeitpunkt der Erstellung der Datei ist nkcht unbedingt der des Sub-Events
                $tstamp += 30;
            }
        }

        $this->SendDebug(__FUNCTION__, 'tstamp=' . date('d.m.Y H:i:s', $tstamp) . ', video_id=' . $video_id . ', filename=' . $filename . ' => ' . ($ok ? 'exists' : 'MISSING'), 0);

        return $ok ? $filename : false;
    }

    public function SearchNotification(string $id)
    {
        $notification = false;
        $data = $this->GetMediaData('Notifications');
        $notifications = json_decode((string) $data, true);
        foreach ($notifications as $n) {
            if ($n['id'] == $id) {
                $notification = $n;
                break;
            }
        }
        $this->SendDebug(__FUNCTION__, 'id=' . $id . ', notification=' . print_r($notification, true), 0);
        return $notification;
    }

    public function GetSnapshotUrl4Notification(string $notification_id, bool $preferLocal)
    {
        $url = false;
        $notification = $this->SearchNotification($notification_id);
        if ($notification != false) {
            if (isset($notification['snapshot'])) {
                $snapshot = $notification['snapshot'];
                if (isset($snapshot['id']) && isset($snapshot['key'])) {
                    $url = $this->GetPictureUrl($snapshot['id'], $snapshot['key']);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'notification_id=' . $notification_id . ', url=' . $url, 0);
        return $url;
    }

    public function GetVignetteUrl4Notification(string $notification_id, bool $preferLocal)
    {
        $url = false;
        $notification = $this->SearchNotification($notification_id);
        if ($notification != false) {
            if (isset($notification['vignette'])) {
                $vignette = $notification['vignette'];
                if (isset($vignette['id']) && isset($vignette['key'])) {
                    $url = $this->GetPictureUrl($vignette['id'], $vignette['key']);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'notification_id=' . $notification_id . ', url=' . $url, 0);
        return $url;
    }

    public function SearchEvent(string $event_id)
    {
        $event = false;
        $data = $this->GetMediaData('Events');
        $events = json_decode((string) $data, true);
        foreach ($events as $e) {
            if ($e['id'] == $event_id) {
                $event = $e;
                break;
            }
        }
        $this->SendDebug(__FUNCTION__, 'event_id=' . $event_id . ', event=' . print_r($event, true), 0);
        return $event;
    }

    public function SearchSubEvent(string $subevent_id)
    {
        $subevent = false;
        $data = $this->GetMediaData('Events');
        $events = json_decode((string) $data, true);
        foreach ($events as $event) {
            if (!isset($event['subevents'])) {
                continue;
            }
            foreach ($event['subevents'] as $e) {
                if ($e['id'] == $subevent_id) {
                    $subevent = $e;
                    break;
                }
            }
            if ($subevent != false) {
                break;
            }
        }
        $this->SendDebug(__FUNCTION__, 'subevent_id=' . $subevent_id . ', subevent=' . print_r($subevent, true), 0);
        return $subevent;
    }

    public function GetVideoUrl4Event(string $event_id, string $resolution, bool $preferLocal)
    {
        global $_SERVER;

        $url = false;
        $event = $this->SearchEvent($event_id);
        if ($event != false) {
            if (isset($event['video_id'])) {
                $video_id = $event['video_id'];
                $tstamp = $event['tstamp'];

                $searchFile = $tstamp != '';
                if (!isset($_SERVER['HTTP_USER_AGENT']) || !isset($_SERVER['HTTP_HOST'])) {
                    $searchFile = false;
                }
                if ($searchFile) {
                    $filename = $this->GetVideoFilename($video_id, $tstamp);
                    $this->SendDebug(__FUNCTION__, 'filename=' . $filename, 0);
                    if ($filename != '') {
                        $path = IPS_GetKernelDir() . 'webfront';
                        if ($path == substr($filename, 0, strlen($path))) {
                            $path = substr($filename, strlen($path));
                            if ($preferLocal) {
                                $url = $this->GetLocalServerUrl();
                            }
                            if ($url == false && isset($_SERVER['HTTP_HOST'])) {
                                $url = isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) ? 'https' : 'http';
                                $url .= '://' . $_SERVER['HTTP_HOST'];
                            }
                            if ($url == false) {
                                $url = $this->GetServerUrl();
                            }
                            if ($url != false) {
                                $url .= $path;
                            }
                        }
                    }
                }
                if ($url == false) {
                    $url = $this->GetVideoUrl($video_id, $resolution, $preferLocal);
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'event_id=' . $event_id . ', url=' . $url, 0);
        return $url;
    }

    public function GetSnapshotUrl4Event(string $event_id, bool $preferLocal)
    {
        $url = false;
        $event = $this->SearchEvent($event_id);
        if ($event != false) {
            if (isset($event['snapshot'])) {
                $snapshot = $event['snapshot'];
                if (isset($snapshot['id']) && isset($snapshot['key'])) {
                    $url = $this->GetPictureUrl($snapshot['id'], $snapshot['key']);
                }
                if (isset($snapshot['filename'])) {
                    $url = $this->GetPictureUrl4Filename($snapshot['filename'], $preferLocal);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'event_id=' . $event_id . ', url=' . $url, 0);
        return $url;
    }

    public function GetVignetteUrl4Event(string $event_id, bool $preferLocal)
    {
        $url = false;
        $event = $this->SearchEvent($event_id);
        if ($event != false) {
            if (isset($event['vignette'])) {
                $vignette = $event['vignette'];
                if (isset($vignette['id']) && isset($vignette['key'])) {
                    $url = $this->GetPictureUrl($vignette['id'], $vignette['key']);
                }
                if (isset($vignette['filename'])) {
                    $url = $this->GetPictureUrl4Filename($vignette['filename'], $preferLocal);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'event_id=' . $event_id . ', url=' . $url, 0);
        return $url;
    }

    public function GetSnapshotUrl4Subevent(string $subevent_id, bool $preferLocal)
    {
        $url = false;
        $subevent = $this->SearchSubEvent($subevent_id);
        if ($subevent != false) {
            if (isset($subevent['snapshot'])) {
                $snapshot = $subevent['snapshot'];
                if (isset($snapshot['id']) && isset($snapshot['key'])) {
                    $url = $this->GetPictureUrl($snapshot['id'], $snapshot['key']);
                }
                if (isset($snapshot['filename'])) {
                    $url = $this->GetPictureUrl4Filename($snapshot['filename'], $preferLocal);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'subevent_id=' . $subevent_id . ', url=' . $url, 0);
        return $url;
    }

    public function GetVignetteUrl4Subevent(string $subevent_id, bool $preferLocal)
    {
        $url = false;
        $subevent = $this->SearchSubEvent($subevent_id);
        if ($subevent != false) {
            if (isset($subevent['vignette'])) {
                $vignette = $subevent['vignette'];
                if (isset($vignette['id']) && isset($vignette['key'])) {
                    $url = $this->GetPictureUrl($vignette['id'], $vignette['key']);
                }
                if (isset($vignette['filename'])) {
                    $url = $this->GetPictureUrl4Filename($vignette['filename'], $preferLocal);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'subevent_id=' . $subevent_id . ', url=' . $url, 0);
        return $url;
    }

    private function ipInCIDR($ip, $cidr)
    {
        list($net, $mask) = explode('/', $cidr);
        $ip_net = ip2long($net);
        if (preg_match('/[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}/', $mask)) {
            $ip_mask = ip2long($mask);
        } else {
            $ip_mask = ~((1 << (32 - $mask)) - 1);
        }
        $ip_ip = ip2long($ip);
        return ($ip_ip & $ip_mask) == ($ip_net & $ip_mask);
    }

    private function deternmineIp($host)
    {
        if (preg_match('/[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}/', $host)) {
            $ip = $host;
        } else {
            $ip = gethostbyname($host);
            if ($host == $ip) {
                $ip = false;
            }
        }
        return $ip;
    }

    private function buildHtml($url)
    {
        $html = '<html>';
        if (preg_match('/\.mp4$/', $url)) {
            $html .= '<body>';
            $html .= '<video>';
            $html .= '  <source src="' . $url . '" type="video/mp4" />';
            $html .= '</video>';
            $html .= '</body>';
        } elseif (preg_match('/\.m3u8$/', $url)) {
            $html .= '<head>';
            $html .= '<meta http-equiv="refresh" content="0; url=' . $url . '">';
            $html .= '</head>';
            $html .= '<body>';
            $html .= '</body>';
        } elseif (preg_match('/\.jpg$/', $url)) {
            $html .= '<head>';
            $html .= '<meta http-equiv="refresh" content="0; url=' . $url . '">';
            $html .= '</head>';
            $html .= '<body>';
            $html .= '</body>';
        } else {
            $html .= '<head>';
            $html .= '<meta http-equiv="refresh" content="0; url=' . $url . '">';
            $html .= '</head>';
            $html .= '<body>';
            $html .= '</body>';
        }
        $html .= '</html>';

        return $html;
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
        if (substr($uri, -1) != '/') {
            $hook .= '/';
        }
        $path = parse_url($uri, PHP_URL_PATH);
        $basename = substr($path, strlen($hook));
        $command = $basename;
        if (substr($command, 0, 1) == '/') {
            $command = substr($command, 1);
        }
        $this->SendDebug(__FUNCTION__, 'command=' . $command, 0);

        $externalIP = $this->ReadPropertyString('externalIP');
        $localCIDRs = $this->ReadPropertyString('localCIDRs');

        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] != '') {
            $ip = $this->deternmineIp($_SERVER['HTTP_X_FORWARDED_FOR']);
            $s = 'HTTP_X_FORWARDED_FOR=' . $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] != '') {
            $ip = $this->deternmineIp($_SERVER['REMOTE_ADDR']);
            $s = 'REMOTE_ADDR=' . $_SERVER['REMOTE_ADDR'];
        } else {
            $ip = false;
            $s = 'HTTP_HOST=' . $_SERVER['HTTP_HOST'];
        }

        $this->SendDebug(__FUNCTION__, 'externalIP=' . $externalIP . ', localCIDRs=' . $localCIDRs . ', ' . $s, 0);

        if ($ip != false) {
            $preferLocal = false;
            if ($localCIDRs != '') {
                $cidrs = explode(';', $localCIDRs);
                foreach ($cidrs as $cidr) {
                    $preferLocal = $this->ipInCIDR($ip, $cidr);
                    if ($preferLocal) {
                        break;
                    }
                }
            }
            if (!$preferLocal && $externalIP != '') {
                $external_ip = $this->deternmineIp($externalIP);
                if ($external_ip != false) {
                    $preferLocal = $ip == $external_ip;
                }
            }
            $this->SendDebug(__FUNCTION__, 'ip=' . $ip . ', preferLocal=' . $this->bool2str($preferLocal), 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'HTTP_HOST=' . $_SERVER['HTTP_HOST'], 0);
            $preferLocal = !preg_match('/\.ipmagic.de$/', $_SERVER['HTTP_HOST']);
            $this->SendDebug(__FUNCTION__, 'preferLocal=' . $this->bool2str($preferLocal), 0);
        }

        $webhook_script = $this->ReadPropertyInteger('webhook_script');

        $mode = isset($_GET['result']) ? $_GET['result'] : 'html';
        $opts = [
            'InstanceID'  => $this->InstanceID,
            'command'     => $command,
            'preferLocal' => $preferLocal,
            '_SERVER'     => json_encode($_SERVER),
            '_GET'        => json_encode($_GET),
        ];

        $url = false;
        $alternate_url = false;

        switch ($command) {
            case 'video':
                if (isset($_GET['live'])) {
                    $resolution = isset($_GET['resolution']) ? $_GET['resolution'] : 'high';
                    $this->SendDebug(__FUNCTION__, 'option: live, resolution=' . $resolution, 0);
                    $url = $this->GetLiveVideoUrl($resolution, $preferLocal);
                    $alternate_url = $this->GetLiveSnapshotUrl($preferLocal);
                }
                if (isset($_GET['event_id'])) {
                    $event_id = $_GET['event_id'];
                    $resolution = isset($_GET['resolution']) ? $_GET['resolution'] : 'high';
                    $this->SendDebug(__FUNCTION__, 'option: event_id=' . $event_id . ', resolution=' . $resolution, 0);
                    if ($event_id == '') {
                        http_response_code(404);
                        die('event_id missing');
                    }
                    $url = $this->GetVideoUrl4Event($event_id, $resolution, $preferLocal);
                    $event = $this->SearchEvent($event_id);
                    if ($event != false && isset($event['subevents'])) {
                        $subevents = $event['subevents'];
                        foreach ($subevents as $subevent) {
                            $alternate_url = $this->GetSnapshotUrl4Subevent($subevent['id'], $preferLocal);
                            if ($alternate_url != false) {
                                break;
                            }
                        }
                    }
                }
                break;
            case 'snapshot':
                if (isset($_GET['live'])) {
                    $this->SendDebug(__FUNCTION__, 'option: live', 0);
                    $url = $this->GetLiveSnapshotUrl($preferLocal);
                }
                if (isset($_GET['subevent_id'])) {
                    $subevent_id = $_GET['subevent_id'];
                    $this->SendDebug(__FUNCTION__, 'option: subevent_id=' . $subevent_id, 0);
                    if ($subevent_id == '') {
                        http_response_code(404);
                        die('subevent_id missing');
                    }
                    $url = $this->GetSnapshotUrl4Subevent($subevent_id, $preferLocal);
                }
                if (isset($_GET['event_id'])) {
                    $event_id = $_GET['event_id'];
                    $this->SendDebug(__FUNCTION__, 'option: event_id=' . $event_id, 0);
                    if ($event_id == '') {
                        http_response_code(404);
                        die('event_id missing');
                    }
                    $url = $this->GetSnapshotUrl4Event($event_id, $preferLocal);
                }
                if (isset($_GET['notification_id'])) {
                    $notification_id = $_GET['notification_id'];
                    $this->SendDebug(__FUNCTION__, 'option: notification_id=' . $notification_id, 0);
                    if ($notification_id == '') {
                        http_response_code(404);
                        die('notification_id missing');
                    }
                    $url = $this->GetSnapshotUrl4Notification($notification_id, $preferLocal);
                }
                break;
            case 'vignette':
                if (isset($_GET['subevent_id'])) {
                    $subevent_id = $_GET['subevent_id'];
                    $this->SendDebug(__FUNCTION__, 'option: subevent_id=' . $subevent_id, 0);
                    if ($subevent_id == '') {
                        http_response_code(404);
                        die('subevent_id missing');
                    }
                    $url = $this->GetVignetteUrl4Subevent($subevent_id, $preferLocal);
                }
                if (isset($_GET['event_id'])) {
                    $notification_id = $_GET['event_id'];
                    $this->SendDebug(__FUNCTION__, 'option: event_id=' . $event_id, 0);
                    if ($event_id == '') {
                        http_response_code(404);
                        die('event_id missing');
                    }
                    $url = $this->GetVignetteUrl4Event($event_id, $preferLocal);
                }
                if (isset($_GET['notification_id'])) {
                    $notification_id = $_GET['notification_id'];
                    $this->SendDebug(__FUNCTION__, 'option: notification_id=' . $notification_id, 0);
                    if ($notification_id == '') {
                        http_response_code(404);
                        die('notification_id missing');
                    }
                    $url = $this->GetVignetteUrl4Notification($notification_id, $preferLocal);
                }
                break;
            case 'timelaps':
                if (isset($_GET['date'])) {
                    $date = strtotime($$_GET['date']);
                    if ($date == 0) {
                        http_response_code(404);
                        die('malformed date');
                    }
                } else {
                    $date = time() - (24 * 60 * 60);
                }
                $this->SendDebug(__FUNCTION__, 'option: date=' . date('d.m.Y', $date), 0);
                $url = $this->GetTimelapseUrl($date, $preferLocal);
                break;
            case 'script':
                if (IPS_ScriptExists($webhook_script) == false) {
                    http_response_code(404);
                    die('no custom-script not found!');
                }
                $this->SendDebug(__FUNCTION__, 'opts=' . print_r($opts, true), 0);
                $html = IPS_RunScriptWaitEx($webhook_script, $opts);
                $this->SendDebug(__FUNCTION__, 'webhook_script=' . IPS_GetName($webhook_script), 0);
                break;
            default:
                break;
        }
        switch ($command) {
            case 'video':
            case 'snapshot':
            case 'vignette':
            case 'timelaps':
                $this->SendDebug(__FUNCTION__, 'url=' . $url . ', alternate_url=' . $alternate_url, 0);
                switch ($mode) {
                    case 'url':
                        $html = $url;
                        break;
                    case 'custom':
                        if (IPS_ScriptExists($webhook_script) == false) {
                            http_response_code(404);
                            die('no custom-script not found!');
                        }
                        $opts['url'] = $url;
                        if ($alternate_url != false) {
                            $opts['alternate_url'] = $alternate_url;
                        }
                        $this->SendDebug(__FUNCTION__, 'opts=' . print_r($opts, true), 0);
                        $html = IPS_RunScriptWaitEx($webhook_script, $opts);
                        $this->SendDebug(__FUNCTION__, 'webhook_script=' . IPS_GetName($webhook_script), 0);
                        break;
                    case 'html':
                        if ($url == false) {
                            http_response_code(404);
                            die('File not found!');
                        }
                        $html = $this->buildHtml($url);
                        break;
                    default:
                        http_response_code(404);
                        die('unknown result-mode!');
                }
                $this->SendDebug(__FUNCTION__, 'html=' . $html, 0);
                echo $html;
                break;
            case 'script':
                break;
            default:
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
                break;
        }
    }

    public function LoadTimelapse()
    {
        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return false;
        }

        $path = $this->ReadPropertyString('timelapse_path');
        if ($path == '') {
            $this->SendDebug(__FUNCTION__, 'no path', 0);
            return false;
        }

        $url = $this->determineUrl();
        if ($url == false) {
            $err = 'no url available';
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            return false;
        }

        $url .= '/command/dl/timelapse';
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);

        if (substr($path, 0, 1) != DIRECTORY_SEPARATOR) {
            $path = IPS_GetKernelDir() . $path;
        }
        if (substr($path, -1) != DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
        }

        if (!file_exists($path)) {
            if (!mkdir($path, 0777, true)) {
                $this->SendDebug(__FUNCTION__, 'unable to create directory ' . $path, 0);
                return false;
            }
        }

        $refdate = time() - (24 * 60 * 60);
        $y = date('Y', $refdate);
        $m = date('m', $refdate);
        $d = date('d', $refdate);

        $product_id = $this->ReadPropertyString('product_id');
        $id = str_replace(':', '', $product_id);

        $basename = $path . $y . '-' . $m . '-' . $d . '_timelapse_' . $id;
        $tmpfile = $basename . '.tmp';

        $fp = fopen($tmpfile, 'w');
        if ($fp == false) {
            $this->SendDebug(__FUNCTION__, 'unabloe to open file "' . $tmpfile . '"', 0);
            return false;
        }

        $time_start = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        $cdata = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, '    cdata=' . $cdata, 0);

        $ok = true;
        if (fclose($fp) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to close file', 0);
            return false;
        }

        if ($cerrno || $httpcode != 200) {
            if (unlink($tmpfile) == false) {
                $this->SendDebug(__FUNCTION__, 'unable to delete file "' . $tmpfile . '"', 0);
            }
            return false;
        }

        $filename = $basename . '.mp4';
        if (file_exists($filename) && unlink($filename) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to delete file "' . $filename . '"', 0);
            return false;
        }
        if (rename($tmpfile, $filename) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to rename file "' . $tmpfile . '" to "' . $filename . '"', 0);
            return false;
        }
        $stat = stat($filename);
        if ($stat == false) {
            $this->SendDebug(__FUNCTION__, 'unable to stat file "' . $filename . '"', 0);
            return false;
        }

        $size = floor($stat['size'] / (1024 * 1024) * 10) / 10;
        $this->SendDebug(__FUNCTION__, 'filename=' . $filename . ', size=' . $size . 'MB', 0);

        return true;
    }

    public function GetTimelapseFilename(int $refdate)
    {
        $path = $this->ReadPropertyString('timelapse_path');
        if ($path == '') {
            $this->SendDebug(__FUNCTION__, 'no path', 0);
            return false;
        }

        if (substr($path, 0, 1) != DIRECTORY_SEPARATOR) {
            $path = IPS_GetKernelDir() . $path;
        }
        if (substr($path, -1) != DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
        }

        if ($refdate == 0) {
            $refdate = time();
        }

        $y = date('Y', $refdate);
        $m = date('m', $refdate);
        $d = date('d', $refdate);

        $product_id = $this->ReadPropertyString('product_id');
        $id = str_replace(':', '', $product_id);

        $filename = $path . $y . '-' . $m . '-' . $d . '_timelapse_' . $id . '.mp4';
        $exists = file_exists($filename);

        $this->SendDebug(__FUNCTION__, 'filename=' . $filename . ' => ' . $this->bool2str($exists), 0);
        return $exists ? $filename : false;
    }

    public function GetTimelapseUrl(int $refdate, bool $preferLocal)
    {
        global $_SERVER;

        if (!isset($_SERVER['HTTP_USER_AGENT']) || !isset($_SERVER['HTTP_HOST'])) {
            $this->SendDebug(__FUNCTION__, 'function can\'t be call without "_SERVER"-enviroment', 0);
            return false;
        }

        $url = false;
        $filename = $this->GetTimelapseFilename($refdate);
        if ($filename != false) {
            $path = IPS_GetKernelDir() . 'webfront';
            if ($path == substr($filename, 0, strlen($path))) {
                $path = substr($filename, strlen($path));
                if ($preferLocal) {
                    $url = $this->GetLocalServerUrl();
                }
                if ($url == false && isset($_SERVER['HTTP_HOST'])) {
                    $url = isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) ? 'https' : 'http';
                    $url .= '://' . $_SERVER['HTTP_HOST'];
                }
                if ($url == false) {
                    $url = $this->GetServerUrl();
                }
                if ($url != false) {
                    $url .= $path;
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    public function CleanupVideoPath()
    {
        $path = $this->ReadPropertyString('ftp_path');
        $max_age = $this->ReadPropertyInteger('ftp_max_age');

        if ($path == '' || $max_age < 1) {
            $this->SendDebug(__FUNCTION__, 'no path or no max_age', 0);
            return false;
        }

        $this->LogMessage(__FUNCTION__ . '/' . IPS_GetName($this->InstanceID), KL_NOTIFY);
        $this->cleanupPath($path, $max_age, false);
    }

    public function CleanupTimelapsePath()
    {
        $path = $this->ReadPropertyString('timelapse_path');
        $max_age = $this->ReadPropertyInteger('timelapse_max_age');

        if ($path == '' || $max_age < 1) {
            $this->SendDebug(__FUNCTION__, 'no path or no max_age', 0);
            return false;
        }

        $this->LogMessage(__FUNCTION__ . '/' . IPS_GetName($this->InstanceID), KL_NOTIFY);
        $this->cleanupPath($path, $max_age, false);
    }

    private function doLoadTimelapse()
    {
        $semaphoreID = __CLASS__ . __FUNCTION__;
        $semaphoreTM = 5 * 60 * 1000; // der Abruf des 'Timelapse' dauert 1-2 min

        $this->LogMessage(__FUNCTION__ . '/' . IPS_GetName($this->InstanceID), KL_NOTIFY);
        if (IPS_SemaphoreEnter($semaphoreID, $semaphoreTM)) {
            $this->LoadTimelapse();
            IPS_SemaphoreLeave($semaphoreID);
        } else {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . $semaphoreID . ' is not accessable', 0);
            $this->LogMessage(__FUNCTION__ . '/' . IPS_GetName($this->InstanceID) . ': sempahore ' . $semaphoreID . ' is not accessable', KL_WARNING);
        }
        $this->SetTimer();
    }

    private function doCleanupPath()
    {
        $semaphoreID = __CLASS__ . __FUNCTION__;
        $semaphoreTM = 500;

        $this->LogMessage(__FUNCTION__ . '/' . IPS_GetName($this->InstanceID), KL_NOTIFY);
        if (IPS_SemaphoreEnter($semaphoreID, $semaphoreTM)) {
            $this->CleanupVideoPath();
            IPS_SemaphoreLeave($semaphoreID);
        } else {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . $semaphoreID . ' is not accessable', 0);
            $this->LogMessage(__FUNCTION__ . '/' . IPS_GetName($this->InstanceID) . ': sempahore ' . $semaphoreID . ' is not accessable', KL_WARNING);
        }

        if (IPS_SemaphoreEnter($semaphoreID, $semaphoreTM)) {
            $this->CleanupTimelapsePath();
            IPS_SemaphoreLeave($semaphoreID);
        } else {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . $semaphoreID . ' is not accessable', 0);
            $this->LogMessage(__FUNCTION__ . '/' . IPS_GetName($this->InstanceID) . ': sempahore ' . $semaphoreID . ' is not accessable', KL_WARNING);
        }
        $this->SetTimer();
    }

    private function doMotionRelease()
    {
        $with_motion_detection = $this->ReadPropertyBoolean('with_motion_detection');
        if ($with_motion_detection) {
            $this->SetValue('MotionType', self::$MOTION_TYPE_NONE);
        }
        $this->MaintainTimer('MotionRelease', 0);
    }

    private function cleanupPath($path, $max_age, $verbose)
    {
        if ($path == '' || $max_age < 1) {
            $this->SendDebug(__FUNCTION__, 'no path or no max_age', 0);
            return false;
        }

        $dt = new DateTime(date('d.m.Y 00:00:00', time()));
        $now = (int) $dt->format('U');

        if (substr($path, 0, 1) != DIRECTORY_SEPARATOR) {
            $path = IPS_GetKernelDir() . $path;
        }
        $this->SendDebug(__FUNCTION__, 'cleanup path ' . $path, 0);
        $age = $max_age * 24 * 60 * 60;
        $this->SendDebug(__FUNCTION__, '* cleanup files', 0);

        $n_files_total = 0;
        $n_files_deleted = 0;
        $n_dirs_total = 0;
        $n_dirs_deleted = 0;
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $objects = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($objects as $object) {
            $isFile = $object->isFile();
            if (!$isFile) {
                continue;
            }
            $pathname = $object->getPathname();
            $a = $now - filemtime($pathname);
            $too_young = ($a < $age);
            $n_files_total++;
            if (!$verbose && $too_young) {
                continue;
            }
            $this->SendDebug(__FUNCTION__, '  name=' . $object->getPathname() . ', age=' . floor(($a / 86400)) . ' => ' . ($too_young ? 'skip' : 'delete'), 0);
            if ($too_young) {
                continue;
            }
            $n_files_deleted++;
            if (!unlink($pathname)) {
                $err = 'unable to delete file ' . $pathname;
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            }
        }
        $this->SendDebug(__FUNCTION__, '* cleanup dirs', 0);
        $directory = new RecursiveDirectoryIterator($path);
        $objects = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($objects as $object) {
            $pathname = $object->getPathname();
            $basename = basename($pathname);
            if ($basename == '.' || $basename == '..') {
                continue;
            }
            $isDir = $object->isDir();
            if (!$isDir) {
                continue;
            }
            $n_dirs_total++;

            $filenames = scandir($pathname);
            $n_child = count($filenames) - 2; // not only '.' and '..'

            if (!$verbose && $n_child > 0) {
                continue;
            }
            $this->SendDebug(__FUNCTION__, '  name=' . $pathname . ', childs=' . $n_child . ' => ' . ($n_child > 0 ? 'skip' : 'delete'), 0);
            if ($n_child > 0) {
                continue;
            }
            $n_dirs_deleted++;
            if (!rmdir($pathname)) {
                $err = 'unable to delete directory ' . $pathname;
                $this->SendDebug(__FUNCTION__, $err, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
            }
        }
        $msg = 'files deleted=' . $n_files_deleted . '/' . $n_files_total . ', dirs deleted=' . $n_dirs_deleted . '/' . $n_dirs_total;
        $this->SendDebug(__FUNCTION__, $msg, 0);
        $this->LogMessage(__FUNCTION__ . ': path=' . $path . ', ' . $msg, KL_MESSAGE);
    }

    public function GetLocalServerUrl()
    {
        $ipsIP = $this->ReadPropertyString('ipsIP');
        $ipsPort = $this->ReadPropertyInteger('ipsPort');

        $url = 'http://' . ($ipsIP != '' ? $ipsIP : gethostbyname(gethostname())) . ':' . $ipsPort;
        return $url;
    }

    public function GetServerUrl()
    {
        $url = $this->GetConnectUrl();
        if ($url == false) {
            $url = $this->GetLocalServerUrl();
        }
        return $url;
    }

    // Event-Typ
    private function event_type2icon($val)
    {
        $val2icon = [
            'human'           => 'human.png',
            'animal'          => 'animal.png',
            'vehicle'         => 'car.png',
            'movement'        => 'movements.png',
            'person'          => 'human.png',
            'on'              => 'on_icon.png',
            'off'             => 'off_icon.png',
            'alarm_started'   => 'alarm_started.png',
            'home_away'       => 'home_away.png',
            'light_mode'      => 'light.png',
            'boot'            => 'boot_icon.png',
            'connection'      => 'connection_icon.png',
            'disconnection'   => 'disconnection_icon.png',
            'sd_ok'           => 'sd_ok_icon.png',
            'sd_nok'          => 'sd_nok_icon.png',
            'ftp_ok'          => 'ftp_ok_icon.png',
            'ftp_nok'         => 'ftp_nok_icon.png',
            'update_dl_start' => 'dl_start.png',
            'update_dl_fail'  => 'dl_fail.png',
            'start_update'    => 'start_update_icon.png',
            'end_update'      => 'end_update_icon.png',
            'siren_sounding'  => 'sounding.png',
            'siren_stopped'   => 'stopped.png',

        ];

        if (isset($val2icon[$val])) {
            $img = $val2icon[$val];
        } else {
            $img = false;
        }
        $this->SendDebug(__FUNCTION__, 'val=' . $val . ', img=' . $img, 0);
        return $img;
    }

    private function event_type2text($val)
    {
        $val2txt = [
            'human'           => 'Human',
            'animal'          => 'Animal',
            'vehicle'         => 'Vehicle',
            'movement'        => 'Movement',
            'person'          => 'Person',
            'on'              => 'Monitoring enabled',
            'off'             => 'Monitoring disabled',
            'alarm_started'   => 'Alarm detected',
            'person_away'     => 'Person has left the house',
            'light_mode'      => 'Light',
            'boot'            => 'Camera is booting',
            'connection'      => 'Camera connected',
            'disconnection'   => 'Camera disconnected',
            'sd_ok'           => 'SD-Card ok',
            'sd_nok'          => 'SD-Card not ok',
            'ftp_ok'          => 'FTP successful',
            'ftp_nok'         => 'FTP failed',
            'update_dl_start' => 'Update downloading',
            'update_dl_fail'  => 'Update download failed',
            'start_update'    => 'Update startet',
            'end_update'      => 'Update finished',
            'siren_sounding'  => 'Siren on',
            'siren_stopped'   => 'Siren off',
        ];

        if (isset($val2txt[$val])) {
            $txt = $this->Translate($val2txt[$val]);
        } else {
            $txt = '';
        }
        $this->SendDebug(__FUNCTION__, 'val=' . $val . ', txt=' . $txt, 0);
        return $txt;
    }

    private function map_camera_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$CAMERA_STATUS_OFF;
                break;
            case 'on':
                $val = self::$CAMERA_STATUS_ON;
                break;
            case 'disconnected':
                $val = self::$CAMERA_STATUS_DISCONNECTED;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$CAMERA_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_lightmode_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$LIGHT_STATUS_OFF;
                break;
            case 'on':
                $val = self::$LIGHT_STATUS_ON;
                break;
            case 'auto':
                $val = self::$LIGHT_STATUS_AUTO;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$LIGHT_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_sd_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$SDCARD_STATUS_UNUSABLE;
                break;
            case 'on':
                $val = self::$SDCARD_STATUS_READY;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$SDCARD_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_power_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$POWER_STATUS_BAD;
                break;
            case 'on':
                $val = self::$POWER_STATUS_GOOD;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$POWER_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    // Wifi-Strength
    private function map_wifi_strength($strength)
    {
        if ($strength <= 56) {
            $val = self::$WIFI_HIGH;
        } elseif ($strength <= 71) {
            $val = self::$WIFI_GOOD;
        } elseif ($strength <= 86) {
            $val = self::$WIFI_AVERAGE;
        } else {
            $val = self::$WIFI_BAD;
        }

        return $val;
    }

    private function wifi_strength2text($strength)
    {
        return $this->CheckVarProfile4Value('NetatmoSecurity.WifiStrength', $status);
    }

    private function wifi_strength2icon($strength)
    {
        $strength2icon = [
            'wifi_low.png',
            'wifi_medium.png',
            'wifi_high.png',
            'wifi_full.png',
        ];

        if ($strength >= 0 && $strength < count($strength2icon)) {
            $img = $strength2icon[$strength];
        } else {
            $img = '';
        }
        return $img;
    }

    public function EventType2Icon(string $event_type, bool $asPath)
    {
        $img = $this->event_type2icon($event_type);
        if ($img != false) {
            $hook = $this->ReadPropertyString('hook');
            $img = $hook . '/imgs/' . $img;
        }
        return $img;
    }

    public function EventType2Text(string $event_type)
    {
        $txt = $this->event_type2text($event_type);
    }

    public function WifiStrength2Icon(string $wifi_strength, bool $asPath)
    {
        $img = $this->wifi_strength2icon($wifi_strength);
        if ($img != false) {
            $hook = $this->ReadPropertyString('hook');
            $img = $hook . '/imgs/' . $img;
        }
        return $img;
    }

    public function WifiStrength2Text(string $wifi_strength)
    {
        $txt = $this->wifi_strength2text($wifi_strength);
    }
}
