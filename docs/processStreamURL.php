<?php

declare(strict_types=1);

// Author:    Christopher Wansing
// Created:   13.07.19
// Modified:  21.07.19
//
// Hinweise:
// - Variable:           Create variable of datatype "String" with profile "~HTML-Box"
//   ... with Content:   <iframe width="100%" height="360" src="<IPS-URL>/hook/<HookName>/video?live&result=custom"></iframe>
//                       - <IPS-URL> may be "https://<ipmagic-Adresse>" (preferred) or "http://<IPS-IP>:3777"
//                       - <HookName> ist the name of the Hook (from Configuration of the Instance)
//                       - Instead of "video/live", the command can also be "snapshot/live" etc (see README.md)
//
// - Player Height:      Add '&height=xxx' to the URL in the iframe. Also change the iframe height accordingly (Player height + 20)
//                       no given or empty 'height' meens 'auto'
// - Player Width:       Add '&width=xxx' to the URL in the iframe. Also change the iframe width accordingly (Player width + 20)
//                       no given or empty 'width' meens 'auto'
// - Poster RefreshRate: Add '&refreshRate=xxx' to the URL. Sets the time for when a new Preview JPG should be fetched in seconds
// - AutoPlay:           Add '&autoplay' to the URL in the iframe

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';
$instName = IPS_GetName($_IPS['InstanceID']);

// IPS_LogMessage($scriptName, '_IPS=' . print_r($_IPS, true));

// _SERVER-Variablen bereitstellen
$SERVER = json_decode($_IPS['_SERVER'], true);
// IPS_LogMessage($scriptName, 'SERVER=' . print_r($SERVER, true));

// _GET-Variablen bereitstellen
$GET = json_decode($_IPS['_GET'], true);
// IPS_LogMessage($scriptName, 'GET=' . print_r($GET, true));

// URL's ausleѕen
$url = (string) $_IPS['url'];
$posterURL = isset($_IPS['alternate_url']) ? $_IPS['alternate_url'] : '';

// URL-GET-Parameter parsen
$height = isset($GET['height']) ? $GET['height'] : '340';
$width = isset($GET['width']) ? $GET['width'] : '';
$autoplay = isset($GET['autoplay']) ? true : false;
$refreshRate = isset($GET['refreshRate']) ? $GET['refreshRate'] : '300';

// IPS_LogMessage($scriptName, 'inst=' . $instName . ', height=' . $height . ', width=' . $width . ', autoplay=' . $autoplay . ', refreshRate=' . $refreshRate);

$video_id = 'NetatmoStream_' . substr(uniqid(), -4);

$html = '';

if (preg_match('/\.m3u8$/', $url)) {
    $html = '<link href="https://vjs.zencdn.net/7.6.0/video-js.css" rel="stylesheet">';
    $html .= '<video id="' . $video_id . '" class="video-js vjs-default-skin vjs-big-play-centered" ';
    if ($height != '') {
        $html .= 'height="' . $height . '" ';
    }
    if ($width != '') {
        $html .= 'width="' . $width . '" ';
    }
    $html .= 'controls ';
    if ($autoplay) {
        $html .= 'autoplay ';
    }
    if ($posterURL != '') {
        $html .= 'poster="' . $posterURL . '" ';
    }
    $html .= '>  ';
    $html .= '  <source type="application/x-mpegURL" src="' . $url . '">  ';
    $html .= '</video>    ';

    $html .= '<script src="https://vjs.zencdn.net/7.6.0/video.js"></script>    ';

    $html .= '<script>  ';
    if ($posterURL != '') {
        $html .= 'function refreshPoster() {  ';
        $html .= 'player.poster("' . $posterURL . '?" + new Date().getTime());  ';
        $html .= '}  ';
    }
    $html .= 'var player = videojs("' . $video_id . '");  ';
    if ($posterURL != '' && $refreshRate > 0) {
        $html .= 'player.setInterval(refreshPoster, ' . ($refreshRate * 1000) . ');  ';
    }
    $html .= '</script>';
} elseif (preg_match('/\.jpg$/', $url)) {
    $html = '<img src="' . $url . '" ';
    if ($height != '') {
        $html .= 'height="' . $height . '" ';
    }
    if ($width != '') {
        $html .= 'width="' . $width . '" ';
    }
    $html .= '>';
} elseif (preg_match('/\.mp4$/', $url)) {
    if (preg_match('/firefox/i', $SERVER['HTTP_USER_AGENT']) || (float) IPS_GetKernelVersion() >= 5.2) {
        $html = '<video ';
        if ($height != '') {
            $html .= 'height="' . $height . '" ';
        }
        if ($width != '') {
            $html .= 'width="' . $width . '" ';
        }
        $html .= 'controls ';
        if ($autoplay) {
            $html .= 'autoplay ';
        }
        $html .= '>  ';
        $html .= '<source src="' . $url . '" type="video/mp4">  ';
        $html .= '</video>';
    } else {
        $html = 'MP4 kann nur mit Firefox abgespielt werden, solange noch nicht mindestens IPS 5.2 installiert ist.';
    }
}

$html = preg_replace('/[ ]{2}/', "\n", $html);   // Ersetzt doppelte Leerzeichen durch Zeilenumbrüche

// IPS_LogMessage($scriptName, 'inst=' . $instName . ', html=' . $html);
echo $html;     // Ausgabe zurück an das aufrufende Modul
