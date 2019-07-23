<?

// String-Variable mit Profil "~HTML-Box"
$varID = 52073;

// max. Benachrichtigungen
$max_lines = 20;
// Größe des Video-Fensters
$video_width = 635;
$video_height = 360;

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';
IPS_LogMessage($scriptName, '_IPS=' . print_r($_IPS, true));
		
$instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
$base_url = CC_GetUrl($instID);
if ($base_url == false) {
	$base_url = 'http://' . gethostbyname(gethostname()) . ':3777';
}
IPS_LogMessage($scriptName, 'base_url=' . $base_url);

$instID = $_IPS['InstanceID'];
$events = json_decode($_IPS['new_events'], true);

foreach ($events as $event) {
	$event_id = $event['id'];	
	$event_types = [];
    if (isset($event['subevents'])) {
        $subevents = $event['subevents'];
        foreach ($subevents as $subevent) {
            $event_type = $subevent['event_type'];
            if (!in_array($event_type, $event_types)) {
                $event_types[] = $event_type;
            }
        }
    }
	$event_type = implode($event_types, '+');
	if ($event_type == 'vehicle' && $instID == 24143) {
		$r = NetatmoSecurity_DeleteEvent($instID, $event_id);
		IPS_LogMessage($scriptName, 'delete event ' . $event_id . ' => ' . ($r ? 'ok' : 'fail'));
	}
}

$timeline = '';
$instIDs = IPS_GetInstanceListByModuleID('{06D589CF-7789-44B1-A0EC-6F51428352E6}');
foreach ($instIDs as $instID) {
	$data = NetatmoSecurity_GetTimeline($instID, false);
	$timeline = NetatmoSecurity_MergeTimeline($instID, $timeline, $data, $instID);
}
$timeline = json_decode($timeline, true);

$html = '';

$html .= '<script type="text/javascript">' . PHP_EOL;
$html .= 'function set_video(url) {' . PHP_EOL;
$html .= '   document.getElementById("event_video").src = url;' . PHP_EOL;
$html .= '   console.log("url=" + url);' . PHP_EOL;
$html .= '}' . PHP_EOL;
$html .= '</script>' . PHP_EOL;

$html .= '<style>' . PHP_EOL;
$html .= 'th, td { padding: 2px 10px; text-align: left; }' . PHP_EOL;
$html .= '</style>' . PHP_EOL;
$html .= '<table>' . PHP_EOL;
$html .= '<tr>' . PHP_EOL;
$html .= '<th>Zeitpunkt</th>' . PHP_EOL;
$html .= '<th>Typ</th>' . PHP_EOL;
$html .= '<th>Meldung</th>' . PHP_EOL;
$html .= '</tr>' . PHP_EOL;

$cur_date = 0;

$event_type2text = [
		'human'    => 'Mensch',
		'animal'   => 'Tier',
		'vehicle'  => 'Fahrzeug',
		'movement' => 'Bewegung'
	];
$event_type2img = [
		'human'    => 'human.png',
		'animal'   => 'animal.png',
		'vehicle'  => 'car.png',
		'movement' => 'movements.png'
	];

$n_timeline = count($timeline);
for ($n = 0, $i = $n_timeline - 1; $n < $max_lines && $i >= 0; $n++, $i--) {
	$item = $timeline[$i];
	
	$event_id = $item['id'];
	$tstamp = $item['tstamp'];
	
	$dt = new DateTime(date('d.m.Y 00:00:00', $tstamp));
    $ts = $dt->format('U');
	if ($cur_date != $ts) {
		if ($cur_date != 0) {
			$html .= '<tr>' . PHP_EOL;
			$html .= '<td>&nbsp;</td>' . PHP_EOL;
			$html .= '<td colspan=2>' . strftime('%A, %e. %B', $tstamp) . '</td>' . PHP_EOL;
			$html .= '</tr>' . PHP_EOL;
		}
		$cur_date = $ts;
	}
	
	$html .= '<tr>' . PHP_EOL;
	$html .= '<td>' . date('H:i', $tstamp) . '</td>' . PHP_EOL;
	
	$instID = $item['tag'];
	$hook = IPS_GetProperty($instID, 'hook');
	$img_path =  $hook . '/imgs/';
	$hook_url = $base_url . $hook;
			
	if (isset($item['push_type'])) {
		$html .= '<td>';
		$message = isset($item['message']) ? $item['message'] : '';
		if (isset($item['event_type'])) {
			$event_type = $item['event_type'];
			if (isset($event_type2img[$event_type])) {
				$event_type_img = $img_path . $event_type2img[$event_type];
			} else {
				$event_type_img = '';
			}
			if (isset($event_type2text[$event_type])) {
				$event_type_text = $event_type2text[$event_type];
			} else {
				$event_type_text = $event_type;
			}
			if ($event_type_img != '') {
				$html .= '<img src=' . $event_type_img . ' width="40" height="40" title="' . $event_type_text . '">';
			} else if ($message == '') {
				$html .= $event_type_text;
			} else {
				$html .= '&nbsp;';
			}
		} else {
			$html .= $event_type;
		}
		$html .= '</td>' . PHP_EOL;

		$html .= '<td>' . $message . '</td>' . PHP_EOL;
	} else {
		$html .= '<td>';
		if (isset($item['event_types'])) {
			$event_types = $item['event_types'];
			foreach ($event_types as $event_type) {
				if (isset($event_type2img[$event_type])) {
					$event_type_img = $img_path . $event_type2img[$event_type];
					if (isset($event_type2text[$event_type])) {
						$event_type_text = $event_type2text[$event_type];
					} else {
						$event_type_text = $event_type;
					}
					$html .= '<img src=' . $event_type_img . ' width="40" height="40" title="' . $event_type_text . '">';
					$html .= '&nbsp;';
				}					
			}
		} else {
			$html .= '&nbsp;';
		}
		$html .= '</td>' . PHP_EOL;
		
		$video_url = $hook_url . '/video?event_id=' . $event_id . '&result=custom&refresh=0';
		$html .= '<td onclick="set_video(\'' . $video_url . '\')">' . PHP_EOL;
		if (isset($item['subevents'])) {
			$subevents = $item['subevents'];
            foreach ($subevents as $subevent) {
				$subevent_id = $subevent['id'];
				$url = NetatmoSecurity_GetVignetteUrl4Subevent($instID, $subevent_id, false);
				if ($url != false) {
					$html .= '<img src=' . $url . ' width="60" height="60">';
					$html .= '&nbsp;&nbsp;';
				}
			}
		}
		$html .= '</td>' . PHP_EOL;
	}
    
    $html .= '</tr>' . PHP_EOL;
}
$html .= '</table>' . PHP_EOL;

$html .= '<iframe id="event_video" width="' . $video_width . '" height="' . $video_height . '" frameborder="0" src=""></iframe>' . PHP_EOL;

SetValueString($varID, $html);
