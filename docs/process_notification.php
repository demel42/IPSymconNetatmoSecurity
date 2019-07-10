<?

$max_lines = 20;

$instID = $_IPS['InstanceID'];
$varID = 1234 /* HTML-Box */;

$html = '<style>' . PHP_EOL;
$html .= 'th, td { padding: 2px 10px; text-align: left; }' . PHP_EOL;
$html .= '</style>' . PHP_EOL;
$html .= '<table>' . PHP_EOL;
$html .= '<tr>' . PHP_EOL;
$html .= '<th>Zeitpunkt</th>' . PHP_EOL;
$html .= '<th>Meldung</th>' . PHP_EOL;
$html .= '</tr>' . PHP_EOL;

$data = NetatmoSecurityCamera_GetNotifications($instID);
if ($data != '') {
	$notifications = json_decode($data, true);
	$n_notifications = count($notifications);
	$msg = 'n_notifications=' . $n_notifications . PHP_EOL;
	for ($n = 0, $i = $n_notifications - 1; $n < $max_lines && $i >= 0; $n++, $i--) {
		$notification = $notifications[$i];
		$tstamp = $notification['tstamp'];
		$message = $notification['message'];
		
		$html .= '<tr>' . PHP_EOL;
        $html .= '<td>' . date('d.m. H:i:s', $tstamp) . '</td>' . PHP_EOL;
        $html .= '<td>' . $message . '</td>' . PHP_EOL;
        $html .= '</tr>' . PHP_EOL;
    }
}
$html .= '</table>' . PHP_EOL;

SetValueString($varID, $html);
