<?php

// Author:    Christopher Wansing
// Created:   13.07.19
// Modified:  21.07.19
//
// Hinweise:
// - Code for ~HTMLBox:  <iframe width="100%" height="360" src="<IPS-URL>/hook/<HookName>/video?live&result=custom"></iframe>
//                       <IPS-URL> kann sein: "https://<ipmagic-Adresse>" oder "http://<IPS-IP>:3777"
//                       <HookName> ist der Name der Hook aus der Konfiguration der Kamera
//                       Anstelle von "video/live" kann das Kommando auch "snapshot/live" etc lauten (siehe README.md)
//
// - Player Height:      Add '&height=xxx' to the URL in the iframe. Also change the iframe height accordingly (Player height + 20)
// - Poster RefreshRate: Add &refreshRate=60000 to the URL. Sets the time for when a new Preview JPG should be fetched in milliseconds
// - AutoPlay:           Add '&autoplay' to the URL in the iframe

// URL-Parameter parsen
$SERVER = json_decode($_IPS['_SERVER'], true);
$GET = json_decode($_IPS['_GET'], true);
$height = isset($GET['height']) ? $GET['height'] : '340';
$autoplay = isset($GET['autoplay']) ? 'autoplay' : '';

$video_id = 'NetatmoStream_' . substr(uniqid(), -4);
$url = $_IPS['url'];
$posterURL = isset($_IPS['alternate_url']) ? 'poster="' . $_IPS['alternate_url'] . '" ' : '';

if (preg_match('/\.m3u8$/', $url)) {
    $html = '<link href="https://vjs.zencdn.net/7.6.0/video-js.css" rel="stylesheet">  ';
    $html .= '<video id="' . $video_id . '" class="video-js vjs-default-skin vjs-big-play-centered" height="' . $height . '" controls ' . $posterURL . $autoplay . '>  ';
    $html .= '<source type="application/x-mpegURL" src="' . $url . '">  ';
    $html .= '</video>    ';

    $html .= '<script src="https://vjs.zencdn.net/7.6.0/video.js"></script>    ';

    $html .= '<script>  ';
    $html .= 'function refreshPoster() {  ';
    $html .= 'player.poster("' . $posterURL . '?" + new Date().getTime());  ';
    $html .= '}  ';
    $html .= 'var player = videojs("' . $video_id . '");  ';
    $html .= 'player.setInterval(refreshPoster, ' . $refreshRate . ');  ';
    $html .= '</script>';
} elseif (preg_match('/\.jpg$/', $url)) {
    $html = '<img src="' . $url . '" height="' . $height . '">';
} elseif (preg_match('/\.mp4$/', $url)) {
    if (preg_match('/firefox/i', $_SERVER['HTTP_USER_AGENT']) || preg_match('/[5-9]\.[2-9]/', IPS_GetKernelVersion())) {
        $html = '<video height="' . $height . '">  ';
        $html .= '<source src="' . $url . '" type="video/mp4">  ';
        $html .= '</video>';
    } else {
        $html = 'MP4 kann nur mit Firefox abgespielt werden, solange noch nicht mindestens IPS 5.2 installiert ist.';
    }
}

$html = preg_replace('/[ ]{2}/', "\n", $html);   //Ersetzt doppelte Leerzeichen durch Zeilenumbrüche

echo $html;     // Ausgabe zurück an das aufrufende Modul
