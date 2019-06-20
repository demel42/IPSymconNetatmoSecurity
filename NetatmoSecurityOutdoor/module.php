<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/library.php';  // globale Funktionen

class NetatmoSecurityOutdoor extends IPSModule
{
    use NetatmoSecurityCommon;
    use NetatmoSecurityLibrary;

    public function Create()
    {
        parent::Create();

		$this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('product_type', '');
        $this->RegisterPropertyString('product_id', '');
        $this->RegisterPropertyString('home_id', '');

		$this->RegisterPropertyBoolean('with_last_contact', false);

		$this->RegisterPropertyInteger('event_max_age', '99');
		$this->RegisterPropertyInteger('notification_max_age', '1');

        $associations = [];
        $associations[] = ['Wert' => -1, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => 0, 'Name' => $this->Translate('off'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => 1, 'Name' => $this->Translate('on'), 'Farbe' => -1];
        $this->CreateVarProfile('NetatmoSecurity.CameraStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations);

        $associations = [];
        $associations[] = ['Wert' => -1, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => 0, 'Name' => $this->Translate('off'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => 1, 'Name' => $this->Translate('on'), 'Farbe' => -1];
        $this->CreateVarProfile('NetatmoSecurity.SDCardStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations);

        $associations = [];
        $associations[] = ['Wert' => -1, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => 0, 'Name' => $this->Translate('off'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => 1, 'Name' => $this->Translate('on'), 'Farbe' => -1];
        $this->CreateVarProfile('NetatmoSecurity.AlimStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations);

        $associations = [];
        $associations[] = ['Wert' => -1, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000];
        $associations[] = ['Wert' => 0, 'Name' => $this->Translate('off'), 'Farbe' => -1];
        $associations[] = ['Wert' => 1, 'Name' => $this->Translate('on'), 'Farbe' => -1];
        $associations[] = ['Wert' => 2, 'Name' => $this->Translate('auto'), 'Farbe' => -1];
        $this->CreateVarProfile('NetatmoSecurity.LightModeStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

		$module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

		$with_last_contact = $this->ReadPropertyBoolean('with_last_contact');

        $vpos = 1;

		$this->MaintainVariable('Status', $this->Translate('State'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $vpos++, true);
		$this->MaintainVariable('LastContact', $this->Translate('Last communication'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_last_contact);

		$this->MaintainVariable('CameraStatus', $this->Translate('Camera state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.CameraStatus', $vpos++, true);
		$this->MaintainVariable('SDCardStatus', $this->Translate('SD-Card state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.SDCardStatus', $vpos++, true);
		$this->MaintainVariable('AlimStatus', $this->Translate('Alim state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.AlimStatus', $vpos++, true);
		$this->MaintainVariable('LightmodeStatus', $this->Translate('Lightmode state'), VARIABLETYPE_INTEGER, 'NetatmoSecurity.LightModeStatus', $vpos++, true);

		$this->MaintainVariable('Events', $this->Translate('Events'), VARIABLETYPE_STRING, '', $vpos++, true);
		$this->MaintainVariable('Notifications', $this->Translate('Notifications'), VARIABLETYPE_STRING, '', $vpos++, true);

        $product_type = $this->ReadPropertyString('product_type');
        $product_id = $this->ReadPropertyString('product_id');
        $product_info = $product_id . ' (' . $product_type . ')';
        $this->SetSummary($product_info);

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm()
    {
        $formElements = [];
		$formElements[] = ['type' => 'CheckBox', 'name' => 'module_disable', 'caption' => 'Instance is disabled'];
        $formElements[] = ['type' => 'Label', 'caption' => 'Netatmo Outdoor camera (Presence)'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'product_type', 'caption' => 'Product-Type'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'product_id', 'caption' => 'Product-ID'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'home_id', 'caption' => 'Home-ID'];
		$formElements[] = ['type' => 'Label', 'caption' => 'optional data'];
		$formElements[] = ['type' => 'CheckBox', 'name' => 'with_last_contact', 'caption' => ' ... last communication with Netatmo'];

        $formActions = [];
        $formActions[] = ['type' => 'Label', 'caption' => '____________________________________________________________________________________________________'];
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
		if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, 'instance is inactive, skip', 0);
            return;
        }

        $jdata = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($jdata, true), 0);

        $source = $jdata['Source'];
        $buf = $jdata['Buffer'];

        $home_id = $this->ReadPropertyString('home_id');
        $product_id = $this->ReadPropertyString('product_id');

		$event_max_age = $this->ReadPropertyInteger('event_max_age');
		$notification_max_age = $this->ReadPropertyInteger('notification_max_age');

		$now = time();

        $err = '';
        $statuscode = 0;
        $do_abort = false;

        $this->SendDebug(__FUNCTION__, 'source=' . $source, 0);

        if ($buf != '') {
            $jdata = json_decode($buf, true);
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

			switch ($source) {
				case 'QUERY':
					$homes = $this->GetArrayElem($jdata, 'body.homes', '');
					if ($homes != '') {
						foreach ($homes as $home) {
							if ($home_id != $home['id'] ) continue;
							$cameras = $this->GetArrayElem($home, 'cameras', '');
							if ($cameras != '') {
								foreach ($cameras as $camera) {
									if ($product_id != $camera['id']) continue;

									$this->SendDebug(__FUNCTION__, 'decode camera=' . print_r($camera, true), 0);

									$camera_status = $this->map_camera_status($this->GetArrayElem($camera, 'status', ''));
									if (is_int($camera_status)) {
										$this->SetValue('CameraStatus', $camera_status);
									}

									$sd_status = $this->map_sd_status($this->GetArrayElem($camera, 'sd_status', ''));
									if (is_int($sd_status)) {
										$this->SetValue('SDCardStatus', $sd_status);
									}

									$alim_status = $this->map_alim_status($this->GetArrayElem($camera, 'alim_status', ''));
									if (is_int($alim_status)) {
										$this->SetValue('AlimStatus', $alim_status);
									}

									$light_mode_status = $this->map_lightmode_status($this->GetArrayElem($camera, 'light_mode_status', ''));
									if (is_int($light_mode_status)) {
										$this->SetValue('LightmodeStatus', $light_mode_status);
									}

									$vpn_url = $this->GetArrayElem($camera, 'vpn_url', '');
									$this->SetBuffer('vpn_url', $vpn_url);

									$is_local = $this->GetArrayElem($camera, 'is_local', false);
									$this->SetBuffer('is_local', $is_local);
								}
							}
						}
					}

					$ref_ts = $now - ($event_max_age * 24 * 60 * 60);

					$new_events = [];
					$s = $this->GetValue('Events');
					$old_events = json_decode($s, true);
					if ($old_events != '') {
						foreach ($old_events as $old_event) {
							if ($old_event['tstamp'] < $ref_ts) continue;
							$new_events[] = $old_event;
						}
					}

					$events = $this->GetArrayElem($home, 'events', '');
					if ($events != '') {
						foreach ($events as $event) {
							if ($product_id != $event['camera_id'] ) continue;
							$this->SendDebug(__FUNCTION__, 'decode event=' . print_r($event, true), 0);

							$id = $this->GetArrayElem($event, 'id', '');

							$fnd = false;
							foreach ($new_events as $new_event) {
								if ($new_event['id'] == $id) {
									$fnd = true;
									break;
								}
							}
							if ($fnd) continue;

							$tstamp = $this->GetArrayElem($event, 'event_list.0.time', 0);

							$new_event = [
									'tstamp'      => $tstamp,
									'id'          => $id,
								];

							$video_id = $this->GetArrayElem($event, 'video_id', '');
							if ($video_id != '') {
								$new_event['video_id'] = $video_id;
							}

							$message = $this->GetArrayElem($event, 'message', '');
							if ($message != '') {
								$new_event['message'] = $message;
							}

							$new_subevents = [];
							$subevents = $this->GetArrayElem($event, 'event_list', '');
							if ($subevents != '') {
								foreach ($subevents as $subevent) {
									$id = $this->GetArrayElem($subevent, 'id', '');
									$type = $this->GetArrayElem($subevent, 'type', '');
									$ts = $this->GetArrayElem($subevent, 'time', 0);
									$message = $this->GetArrayElem($subevent, 'message', '');
									$snapshot_id = $this->GetArrayElem($subevent, 'snapshot.id', '');
									$snapshot_key = $this->GetArrayElem($subevent, 'snapshot.key', '');
									$snapshot_filename = $this->GetArrayElem($subevent, 'snapshot.filename', '');
									$vignette_id = $this->GetArrayElem($subevent, 'vignette.id', '');
									$vignette_key = $this->GetArrayElem($subevent, 'vignette.key', '');
									$vignette_filename = $this->GetArrayElem($subevent, 'vignette.filename', '');

									$new_subevent = [
											'id'        => $id,
											'time'      => $ts,
											'type'      => $type,
											'message'   => $message,
											'type'      => $type,
										]; 

									$snapshot = [];
									if ($snapshot_id != []) {
										$snapshot['id'] = $snapshot_id;
									}
									if ($snapshot_key != []) {
										$snapshot['key'] = $snapshot_key;
									}
									if ($snapshot_filename != []) {
										$snapshot['filename'] = $snapshot_filename;
									}
									if ($snapshot != []) {
										$new_subevent['snapshot'] = $snapshot;
									}

									$vignette = [];
									if ($vignette_id != []) {
										$vignette['id'] = $vignette_id;
									}
									if ($vignette_key != []) {
										$vignette['key'] = $vignette_key;
									}
									if ($vignette_filename != []) {
										$vignette['filename'] = $vignette_filename;
									}
									if ($vignette != []) {
										$new_subevent['vignette'] = $vignette;
									}

									$new_subevents[] = $new_subevent;
								}
								$new_event['subevents'] = $new_subevents;
							}

							$new_events[] = $new_event;
						}
					}
					
					if ($new_events != []) {
						usort($new_events, ['NetatmoSecurityOutdoor', 'cmp_events']);
						$s = json_encode($new_events);
					} else {
						$s = '';
					}
					$this->SetValue('Events', $s);

					$status = $this->GetArrayElem($jdata, 'status', '') == 'ok' ? true : false;
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
					$s = $this->GetValue('Notifications');
					$old_notifications = json_decode($s, true);
					if ($old_notifications != '') {
						foreach ($old_notifications as $old_notification) {
							if ($old_notification['tstamp'] < $ref_ts) continue;
							$new_notifications[] = $old_notification;
						}
					}

					$camera_id = $this->GetArrayElem($notification, 'camera_id', '');
					if ($camera_id == '' || $product_id == $camera_id ) {
						$this->SendDebug(__FUNCTION__, 'decode notification=' . print_r($notification, true), 0);

						$push_type = $this->GetArrayElem($notification, 'push_type', '');
						switch ($push_type) {
							case 'NOC-human':
							case 'NOC-animal':
							case 'NOC-vehicle':
								$event_id = $this->GetArrayElem($notification, 'event_id', '');
								$subevent_id = $this->GetArrayElem($notification, 'subevent_id', '');
								$event_type = $this->GetArrayElem($notification, 'event_type', '');
								$message = $this->GetArrayElem($notification, 'message', '');
								$snapshot_id = $this->GetArrayElem($notification, 'snapshot.id', '');
								$snapshot_key = $this->GetArrayElem($notification, 'snapshot.key', '');
								$vignette_id = $this->GetArrayElem($notification, 'vignette.id', '');
								$vignette_key = $this->GetArrayElem($notification, 'vignette.key', '');
								$new_notification = [
										'tstamp'       => $now,
										'id'           => $event_id,
										'push_type'    => $push_type,
										'message'      => $message,
										'subevent_id'  => $subevent_id,
										'event_type'   => $event_type,
										'snapshot_id'  => $snapshot_id,
										'snapshot_key' => $snapshot_key,
										'vignette_id'  => $vignette_id,
										'vignette_key' => $vignette_key,
									]; 
								$new_notifications[] = $new_notification;
								break;
							case 'NOC-light_mode':
							case 'NOC-off':
							case 'NOC-on':
								$id = $this->GetArrayElem($notification, 'id', '');
								$message = $this->GetArrayElem($notification, 'message', '');
								$new_notification = [
										'tstamp'       => $now,
										'id'           => $id,
										'push_type'    => $push_type,
										'message'      => $message,
									];
								$new_notifications[] = $new_notification;
								break;
							case 'webhook_activation':
							case 'daily_summary':
								$new_event = 'ignore push_type "' . $push_type . '"';
								$this->SendDebug(__FUNCTION__, $e, 0);
								$this->LogMessage(__FUNCTION__ . ': ' . $e, KL_INFO);
								break;
							default:
								$err = 'unknown push_type "' . $push_type . '"';
								$this->SendDebug(__FUNCTION__, $err, 0);
								$this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
								break;
						}
					}

					if ($new_notifications != []) {
						usort($new_notifications, ['NetatmoSecurityOutdoor', 'cmp_events']);
						$s = json_encode($new_notifications);
					} else {
						$s = '';
					}
					$this->SetValue('Notifications', $s);
					break;
				default:
					$err = 'unknown source "' . $source . '"';
					$this->SendDebug(__FUNCTION__, $err, 0);
					$this->LogMessage(__FUNCTION__ . ': ' . $err, KL_NOTIFY);
					break;
			}
        }

        $this->SetStatus(IS_ACTIVE);
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

}
// [vpn_url] => https://prodvpn-eu-1.netatmo.net/restricted/10.255.185.151/c9fd79f94b5db715715a24649b707c99/MTU2MDg4MDgwMDrw9KRQ_pmj5eoucTLDTEmShwW-zg,,
