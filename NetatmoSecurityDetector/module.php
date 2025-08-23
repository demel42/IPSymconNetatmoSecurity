<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class NetatmoSecurityDetector extends IPSModule
{
    use NetatmoSecurity\StubsCommonLib;
    use NetatmoSecurityLocalLib;

    private static $api_server = 'api.netatmo.com';

    public static $MUTE_RELEASE = 15 * 60; // 15 Minuten

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyBoolean('log_no_parent', true);

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

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

        $this->RegisterTimer('MuteRelease', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "doMuteRelease", "");');

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

    private function CheckModuleUpdate(array $oldInfo, array $newInfo)
    {
        $r = [];

        if ($this->version2num($oldInfo) < $this->version2num('1.41')) {
            $r[] = $this->Translate('Set ident of media objects');
        }

        return $r;
    }

    private function CompleteModuleUpdate(array $oldInfo, array $newInfo)
    {
        $r = '';

        if ($this->version2num($oldInfo) < $this->version2num('1.41')) {
            $m = [
                'Events'        => '.dat',
                'Notifications' => '.dat',
            ];

            foreach ($m as $ident => $extension) {
                $filename = 'media' . DIRECTORY_SEPARATOR . $this->InstanceID . '-' . $ident . $extension;
                @$mediaID = IPS_GetMediaIDByFile($filename);
                if ($mediaID != false) {
                    IPS_SetIdent($mediaID, $ident);
                }
            }
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

        $product_id = $this->ReadPropertyString('product_id');
        $product_type = $this->ReadPropertyString('product_type');

        $vpos = 1;

        $this->MaintainVariable('Status', $this->Translate('State'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $vpos++, true);
        $this->MaintainVariable('LastContact', $this->Translate('Last communication'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_contact);
        $this->MaintainVariable('LastSeen', $this->Translate('Last seen'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('LastEvent', $this->Translate('Last event'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_event);
        $this->MaintainVariable('LastNotification', $this->Translate('Last notification'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_notification);

        $this->MaintainVariable('WifiStrength', $this->Translate('Strength of wifi-signal'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.WifiStrength', $vpos++, $with_wifi_strength);

        switch ($product_type) {
            case 'NSD':
                $this->MaintainVariable('Alarm', $this->Translate('Smoke detected'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.YesNo', $vpos++, true);
                $this->MaintainVariable('Muted', $this->Translate('Smoke detector is muted'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.YesNo', $vpos++, true);
                $this->MaintainVariable('Tampered', $this->Translate('Smoke detector is tampered'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.YesNo', $vpos++, true);
                $this->MaintainVariable('Dusty', $this->Translate('Smoke chamber is dusty'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.YesNo', $vpos++, true);

                foreach (['Warning'] as $unused_var) {
                    $this->UnregisterVariable($unused_var);
                }
                break;
            case 'NCO':
                $this->MaintainVariable('Alarm', $this->Translate('CO is critical'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.YesNo', $vpos++, true);
                $this->MaintainVariable('Warning', $this->Translate('CO is increased'), VARIABLETYPE_BOOLEAN, 'NetatmoSecurity.YesNo', $vpos++, true);

                foreach (['Muted', 'Tampered', 'Dusty'] as $unused_var) {
                    $this->UnregisterVariable($unused_var);
                }
                break;
            default:
                foreach (['Alarm', 'Warning', 'Muted', 'Tampered', 'Dusty'] as $unused_var) {
                    $this->UnregisterVariable($unused_var);
                }
                break;
        }

        $this->MaintainVariable('SoundTest', $this->Translate('Sound test'), VARIABLETYPE_BOOLEAN, '~Alert', $vpos++, true);
        $this->MaintainVariable('WifiStatus', $this->Translate('Wifi status'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $vpos++, true);

        $vpos = 100;
        $this->MaintainMedia('Events', $this->Translate('Events'), MEDIATYPE_DOCUMENT, '.dat', false, $vpos++, true);
        $this->MaintainMedia('Notifications', $this->Translate('Notifications'), MEDIATYPE_DOCUMENT, '.dat', false, $vpos++, true);

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
            case 'NCO':
                $product_type_s = 'Netatmo carbon monoxide detector';
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

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'log_no_parent',
            'caption' => 'Generate message when the gateway is inactive',
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

        /*
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
         */

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
            case 'NCO':
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
                    $n_new_events = 0;
                    $n_chg_events = 0;
                    $n_del_events = 0;
                    $homes = $this->GetArrayElem($jdata, 'states.homes', '');
                    if ($homes != '') {
                        foreach ($homes as $home) {
                            if (isset($home['id']) && $home['id'] != $home_id) {
                                continue;
                            }
                            $modules = $this->GetArrayElem($home, 'modules', '');
                            if ($modules != '') {
                                foreach ($modules as $module) {
                                    if ($product_id != $module['id']) {
                                        continue;
                                    }
                                    $this->SendDebug(__FUNCTION__, 'decode module=' . print_r($module, true), 0);

                                    if ($with_wifi_strength) {
                                        $wifi_strength = $this->map_wifi_strength($this->GetArrayElem($module, 'wifi_strength', ''));
                                        $this->SendDebug(__FUNCTION__, 'wifi_strength=' . $wifi_strength, 0);
                                        $this->SetValue('WifiStrength', $wifi_strength);
                                    }
                                }
                            }

                            $ref_ts = $now - ($event_max_age * 24 * 60 * 60);

                            $cur_events = [];
                            $new_events = [];
                            $s = $this->GetMediaContent('Events');
                            $prev_events = json_decode((string) $s, true);
                            $this->SendDebug(__FUNCTION__, 'prev_events=' . print_r($prev_events, true), 0);
                            $events = $this->GetArrayElem($home, 'events', '');
                            $this->SendDebug(__FUNCTION__, 'events=' . print_r($events, true), 0);
                            if ($events != '') {
                                $this->SendDebug(__FUNCTION__, 'n_events=' . count($events), 0);
                                $vars = [];
                                foreach ($events as $event) {
                                    $this->SendDebug(__FUNCTION__, 'event=' . print_r($event, true), 0);
                                    if ($product_id != $event['module_id']) {
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

                                    $message = $this->GetArrayElem($event, 'message', '');
                                    if ($message != '') {
                                        $new_event['message'] = $message;
                                    }

                                    $type = $this->GetArrayElem($event, 'type', '');
                                    $_type = $type;
                                    $sub_type = '';
                                    $ident = '';
                                    $value = '';
                                    switch ($type) {
                                        case 'hush':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            if ($sub_type == 1) {
                                                $type = 'detector_muted';
                                            } else {
                                                $type = 'detector_armed';
                                            }
                                            break;
                                        case 'smoke':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            if ($sub_type == 1) {
                                                $type = 'smoke_detected';
                                            } else {
                                                $type = 'smoke_not_detected';
                                            }
                                            break;
                                        case 'sound_test':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            $ident = 'SoundTest';
                                            if ($sub_type == 1) {
                                                $type = 'sound_test_failed';
                                                $value = true;
                                            } else {
                                                $type = 'sound_test_passed';
                                                $value = false;
                                            }
                                            break;
                                        case 'siren_sounding':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            if ($sub_type == 1) {
                                                $type = 'siren_sounding';
                                            } else {
                                                $type = 'siren_stopped';
                                            }
                                            break;
                                        case 'chamber_status':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            $ident = 'Dusty';
                                            if ($sub_type == 1) {
                                                $type = 'chamber_dusty';
                                                $value = true;
                                            } else {
                                                $type = 'chamber_clean';
                                                $value = false;
                                            }
                                            break;
                                        case 'tampered':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            $ident = 'Tampered';
                                            if ($sub_type == 1) {
                                                $type = 'detector_tampered';
                                                $value = true;
                                            } else {
                                                $type = 'detector_ready';
                                                $value = false;
                                            }
                                            break;
                                        case 'wifi_status':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            $ident = 'WifiStatus';
                                            if ($sub_type) {
                                                $type = 'wifi_ok';
                                                $value = true;
                                            } else {
                                                $type = 'wifi_error';
                                                $value = false;
                                            }
                                            break;
                                        case 'battery_status':
                                            $sub_type = $this->GetArrayElem($event, 'sub_type', '');
                                            $type = ($sub_type == 1) ? 'battery_low' : 'battery_very_low';
                                            if ($sub_type == 1) {
                                                $type = 'battery_low';
                                            } else {
                                                $type = 'battery_very_low';
                                            }
                                            break;
                                        case 'new_device':
                                            /*
                                            11.04.2023, 10:21:48 |          ReceiveData | decode event=Array
                                            (
                                                [id] => 6435140d7ef077b8e405e7d4
                                                [type] => new_device
                                                [time] => 1681200141
                                                [camera_id] => 70:ee:50:9f:1a:d8
                                                [device_id] => 70:ee:50:9f:1a:d8
                                                [message] => Smart Smoke Alarm: wurde Ihrem Zuhause hinzugefügt
                                            )
                                             */
                                            break;
                                        default:
                                            $this->SendDebug(__FUNCTION__, 'unsupported type=' . $type, 0);
                                            $type = '';
                                            break;
                                    }
                                    if ($type != $_type) {
                                        $this->SendDebug(__FUNCTION__, 'type=' . $_type . ' => ' . $type . ', sub_type=' . $sub_type, 0);
                                    } else {
                                        $this->SendDebug(__FUNCTION__, 'type=' . $type . ', sub_type=' . $sub_type, 0);
                                    }

                                    if ($ident != '') {
                                        $v = isset($vars[$ident]) ? $vars[$ident] : [];
                                        $v['tstamp'] = $tstamp;
                                        $v['value'] = $value;
                                        $vars[$ident] = $v;
                                    }

                                    if ($type != '') {
                                        $new_event['event_type'] = $type;
                                    }

                                    $this->SendDebug(__FUNCTION__, 'new_event=' . print_r($new_event, true), 0);
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
                                        $this->SendDebug(__FUNCTION__, 'new_events=' . print_r($new_events, true), 0);
                                    }
                                }
                                $this->SendDebug(__FUNCTION__, 'vars=' . print_r($vars, true), 0);
                                foreach ($vars as $ident => $v) {
                                    $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', var=' . print_r($v, true), 0);
                                    $tstamp = $v['tstamp'];
                                    $value = $v['value'];
                                    @$varID = $this->GetIDForIdent($ident);
                                    if ($varID == false) {
                                        $this->SendDebug(__FUNCTION__, 'missing variable ' . $ident, 0);
                                        continue;
                                    }
                                    $updated = IPS_GetVariable($varID)['VariableUpdated'];
                                    $curValue = GetValueFormatted($varID);
                                    $newValue = GetValueFormattedEx($varID, $value);
                                    $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', current value=' . $curValue . ' (' . date('d.m.Y H:i:s', $updated) . '), new value=' . $newValue . ' (' . date('d.m.Y H:i:s', $tstamp) . ')', 0);

                                    if ($tstamp > $updated) {
                                        $this->SetValue($ident, $value);
                                    }
                                }
                            }

                            $n_new_events = count($new_events);
                            $this->SendDebug(__FUNCTION__, 'n_new_events=' . $n_new_events, 0);
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
                            $this->SetMediaContent('Events', $s);

                            $with_last_event = $this->ReadPropertyBoolean('with_last_event');
                            if ($with_last_event && $n_new_events > 0) {
                                $this->SetValue('LastEvent', $now);
                            }
                        }
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

                    break;
                case 'PUSH':
                    $ref_ts = $now - ($notification_max_age * 24 * 60 * 60);
                    $notification = $jdata;

                    $new_notifications = [];
                    $cur_notifications = [];
                    $s = $this->GetMediaContent('Notifications');
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

                        $push_type = $this->GetArrayElem($notification, 'push_type', '');

                        $event_id = $this->GetArrayElem($notification, 'event_id', '');
                        $event_type = $this->GetArrayElem($notification, 'event_type', '');
                        $sub_type = $this->GetArrayElem($notification, 'sub_type', '');
                        $message = $this->GetArrayElem($notification, 'message', '');

                        switch ($push_type) {
                            case 'NSD-hush':
                                if ($sub_type) {
                                    $message = $this->Translate('Smoke detector is muted');
                                    $this->SetValue('Muted', true);
                                    $this->MaintainTimer('MuteRelease', self::$MUTE_RELEASE * 1000);
                                } else {
                                    $message = $this->Translate('Smoke detector is armed');
                                    $this->SetValue('Muted', false);
                                    $this->MaintainTimer('MuteRelease', 0);
                                }
                                break;
                            case 'NSD-smoke':
                                if ($sub_type) {
                                    $message = $this->Translate('Smoke detected');
                                    $this->SetValue('Alarm', true);
                                } else {
                                    $message = $this->Translate('Smoke not longer detected');
                                    $this->SetValue('Alarm', false);
                                }
                                break;
                            case 'NSD-tampered':
                                if ($sub_type) {
                                    $message = $this->Translate('Smoke detector is tampered');
                                    $this->SetValue('Tampered', true);
                                } else {
                                    $message = $this->Translate('Smoke detector is ready');
                                    $this->SetValue('Tampered', false);
                                }
                                break;
                            case 'NSD-wifi_status':
                                if ($sub_type) {
                                    $message = $this->Translate('Wifi status is ok');
                                    $this->SetValue('WifiStatus', true);
                                } else {
                                    $message = $this->Translate('Wifi status is bad');
                                    $this->SetValue('WifiStatus', false);
                                }
                                break;
                            case 'NSD-battery_status':
                                if ($sub_type) {
                                    $message = $this->Translate('Battery status is very low');
                                } else {
                                    $message = $this->Translate('Battery status is low');
                                }
                                break;
                            case 'NSD-detection_chamber_status':
                                if ($sub_type) {
                                    $message = $this->Translate('Smoke chamber is dusty');
                                    $this->SetValue('Dusty', true);
                                } else {
                                    $message = $this->Translate('Smoke chamber is clean');
                                    $this->SetValue('Dusty', false);
                                }
                                break;
                            case 'NSD-sound_test': // Sound test result
                                if ($sub_type) {
                                    $message = $this->Translate('Sound test failed');
                                    $this->SetValue('SoundTest', false);
                                } else {
                                    $message = $this->Translate('Sound test passed');
                                    $this->SetValue('SoundTest', false);
                                }
                                break;
                            case 'NCO-co_detected':
                                switch ($sub_type) {
                                    case 0:
                                        $message = $this->Translate('Carbon monoxide not longer detected');
                                        $this->SetValue('Alarm', false);
                                        $this->SetValue('Warning', false);
                                        break;
                                    case 1:
                                        $message = $this->Translate('Carbon monoxide is increased');
                                        $this->SetValue('Warning', true);
                                        break;
                                    case 2:
                                        $message = $this->Translate('Carbon monoxide is critical');
                                        $this->SetValue('Alarm', true);
                                        break;
                                }
                                break;
                            case 'NCO-wifi_status':
                                if ($sub_type) {
                                    $message = $this->Translate('Wifi status is ok');
                                    $this->SetValue('WifiStatus', true);
                                } else {
                                    $message = $this->Translate('Wifi status is bad');
                                    $this->SetValue('WifiStatus', false);
                                }
                                break;
                            case 'NCO-battery_status':
                                if ($sub_type) {
                                    $message = $this->Translate('Battery status is very low');
                                } else {
                                    $message = $this->Translate('Battery status is low');
                                }
                                break;
                            default:
                                break;
                        }

                        switch ($push_type) {
                            case 'NSD-hush':
                            case 'NSD-smoke':
                            case 'NSD-tampered':
                            case 'NSD-wifi_status':
                            case 'NSD-battery_status':
                            case 'NSD-sound_test':
                            case 'NSD-detection_chamber_status':
                            case 'NCO-co_detected':
                            case 'NCO-wifi_status':
                            case 'NCO-battery_status':
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
                        usort($cur_notifications, ['NetatmoSecurityDetector', 'cmp_events']);
                        $s = json_encode($cur_notifications);
                    } else {
                        $s = '';
                    }
                    $this->SetMediaContent('Notifications', $s);

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

    private function build_url($url, $params)
    {
        $p = '';
        if (is_array($params)) {
            $r = [];
            foreach ($params as $param => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $r[] = $param . '=' . rawurlencode(strval($v));
                    }
                } elseif (is_null($value)) {
                    $r[] = $param;
                } else {
                    $r[] = $param . '=' . rawurlencode(strval($value));
                }
            }
            if ($r != []) {
                $p = '?' . implode('&', $r);
            }
        }
        return $url . $p;
    }

    private function build_header($headerfields)
    {
        $header = [];
        foreach ($headerfields as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $header;
    }

    public function DeleteEvent(string $event_id)
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
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

        $url = 'https://' . self::$api_server . '/api/deleteevent';

        $postdata = [
            'home_id'   => $home_id,
            'device_id' => $product_id,
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
            $this->SetMediaContent('Events', json_encode($events));
        }

        return $status == 'ok';
    }

    private function GetHomeStatus()
    {
        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
            return false;
        }

        $home_id = $this->ReadPropertyString('home_id');
        $product_id = $this->ReadPropertyString('product_id');
        $with_wifi_strength = $this->ReadPropertyBoolean('with_wifi_strength');

        $params = [
            'home_id'      => $home['id'],
            'device_types' => ['NSD', 'NCO'],
        ];
        $url = $this->build_url('https://' . self::$api_server . '/api/homestatus', $params);

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'CmdUrlGetWithAuth', 'Url' => $url];
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

                    $last_seen = $this->GetArrayElem($module, 'last_seen', 0);
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
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
            return false;
        }

        $home_id = $this->ReadPropertyString('home_id');
        $product_id = $this->ReadPropertyString('product_id');

        $url = 'https://' . self::$api_server . '/api/homesdata';

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
                    if (isset($home['id']) && $home['id'] != $home_id) {
                        continue;
                    }
                    $this->SendDebug(__FUNCTION__, 'home=' . print_r($home, true), 0);
                }
            }
        }
    }

    public function GetEvents()
    {
        $data = $this->GetMediaContent('Events');
        return $data;
    }

    public function GetNotifications()
    {
        $data = $this->GetMediaContent('Notifications');
        return $data;
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
            case 'doMuteRelease':
                $this->doMuteRelease();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    private function doMuteRelease()
    {
        $this->SetValue('Muted', false);
        $this->MaintainTimer('MuteRelease', 0);
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
