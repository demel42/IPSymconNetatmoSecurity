<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class NetatmoSecurityDetector extends IPSModule
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

        $this->RegisterPropertyInteger('event_max_age', 14);
        $this->RegisterPropertyInteger('notification_max_age', 2);

        $this->RegisterPropertyInteger('new_event_script', 0);
        $this->RegisterPropertyInteger('notify_script', 0);

        $this->RegisterAttributeString('UpdateInfo', '');

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

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

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['new_event_script', 'notify_script'];
        $this->MaintainReferences($propertyNames);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $with_last_contact = $this->ReadPropertyBoolean('with_last_contact');
        $with_last_event = $this->ReadPropertyBoolean('with_last_event');
        $with_last_notification = $this->ReadPropertyBoolean('with_last_notification');
        $with_wifi_strength = $this->ReadPropertyBoolean('with_wifi_strength');

        $vpos = 1;

        $this->MaintainVariable('Status', $this->Translate('State'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $vpos++, true);
        $this->MaintainVariable('LastContact', $this->Translate('Last communication'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_contact);
        $this->MaintainVariable('LastSeen', $this->Translate('Last seen'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('LastEvent', $this->Translate('Last event'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_event);
        $this->MaintainVariable('LastNotification', $this->Translate('Last notification'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_notification);

        $this->MaintainVariable('WifiStrength', $this->Translate('Strength of wifi-signal'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.WifiStrength', $vpos++, $with_wifi_strength);

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

        $this->MaintainStatus(IS_ACTIVE);
    }

    public function MessageSink($tstamp, $senderID, $message, $data)
    {
        parent::MessageSink($tstamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
        }
    }

    private function GetFormElements()
    {
        $product_type = $this->ReadPropertyString('product_type');
        switch ($product_type) {
            case 'NSD':
                $product_type_s = 'Netatmo smoke detector';
                break;
            default:
                $product_type_s = 'Unknown product';
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
            ],
            'caption' => 'optional data'
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
        $this->SendDebug(__FUNCTION__, 'home_id=' . $home_id . ', product_id=' . $product_id, 0);

        $product_type = $this->ReadPropertyString('product_type');
        switch ($product_type) {
            case 'NSD':
                break;
            default:
                break;
        }
        $with_wifi_strength = $this->ReadPropertyBoolean('with_wifi_strength');

        $event_max_age = $this->ReadPropertyInteger('event_max_age');
        $notification_max_age = $this->ReadPropertyInteger('notification_max_age');

        $now = time();

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
                            $smokedetectors = $this->GetArrayElem($home, 'smokedetectors', '');
                            if ($smokedetectors != '') {
                                foreach ($smokedetectors as $smokedetector) {
                                    if ($product_id != $smokedetector['id']) {
                                        continue;
                                    }
                                    $this->SendDebug(__FUNCTION__, 'decode smokedetector=' . print_r($smokedetector, true), 0);
                                }
                            }
                        }
                    }

                    $this->GetHomeStatus();

                    $ref_ts = $now - ($event_max_age * 24 * 60 * 60);

                    $cur_events = [];
                    $new_events = [];
                    $s = $this->GetMediaData('Events');
                    $prev_events = json_decode((string) $s, true);
                    $this->SendDebug(__FUNCTION__, 'prev_events=' . print_r($prev_events, true), 0);
                    $events = $this->GetArrayElem($home, 'events', '');
                    $this->SendDebug(__FUNCTION__, 'events=' . print_r($events, true), 0);
                    if ($events != '') {
                        $this->SendDebug(__FUNCTION__, 'n_events=' . count($events), 0);
                        foreach ($events as $event) {
                            $this->SendDebug(__FUNCTION__, 'event=' . print_r($event, true), 0);
                            if ($product_id != $event['device_id']) {
                                continue;
                            }
                            $this->SendDebug(__FUNCTION__, 'decode event=' . print_r($event, true), 0);

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
                        usort($cur_events, ['NetatmoSecurityDetector', 'cmp_events']);
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
                        usort($cur_events, ['NetatmoSecurityDetector', 'cmp_events']);
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
                    $status = $system_ok;
                    $this->SendDebug(__FUNCTION__, 'states: system=' . $this->bool2str($system_ok) . ' => ' . $this->bool2str($status), 0);
                    $this->SetValue('Status', $status);

                    $with_last_contact = $this->ReadPropertyBoolean('with_last_contact');
                    if ($with_last_contact) {
                        $tstamp = $this->GetArrayElem($jdata, 'time_server', 0);
                        $this->SetValue('LastContact', $tstamp);
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

                    $device_id = $this->GetArrayElem($notification, 'device_id', '');
                    if ($device_id == '' || $product_id == $device_id) {
                        $this->SendDebug(__FUNCTION__, 'decode notification=' . print_r($notification, true), 0);
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
            'gateway_types' => ['NSD', 'NCO'],
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

                    $tstamp = $this->GetArrayElem($module, 'last_seen', 0);
                    $this->SetValue('LastSeen', $last_seen);

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
            'gateway_types' => ['NSD', 'NCO'],
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

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
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
