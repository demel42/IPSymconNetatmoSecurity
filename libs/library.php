<?php

if (!defined('CAMERA_STATUS_UNDEFINED')) {
    define('CAMERA_STATUS_UNDEFINED', -1);
    define('CAMERA_STATUS_OFF', 0);
    define('CAMERA_STATUS_ON', 1);
    define('CAMERA_STATUS_DISCONNECTED', 2);
}

if (!defined('LIGHT_STATUS_UNDEFINED')) {
    define('LIGHT_STATUS_UNDEFINED', -1);
    define('LIGHT_STATUS_OFF', 0);
    define('LIGHT_STATUS_ON', 1);
    define('LIGHT_STATUS_AUTO', 2);
}

if (!defined('SDCARD_STATUS_UNDEFINED')) {
    define('SDCARD_STATUS_UNDEFINED', -1);
    define('SDCARD_STATUS_OFF', 0);
    define('SDCARD_STATUS_ON', 1);
}

if (!defined('ALIM_STATUS_UNDEFINED')) {
    define('ALIM_STATUS_UNDEFINED', -1);
    define('ALIM_STATUS_OFF', 0);
    define('ALIM_STATUS_ON', 1);
}

trait NetatmoSecurityLibrary
{
    private function map_camera_status($status)
    {
        switch ($status) {
            case 'off':
                $val = CAMERA_STATUS_OFF;
                break;
            case 'on':
                $val = CAMERA_STATUS_ON;
                break;
            case 'disconnected':
                $val = CAMERA_STATUS_DISCONNECTED;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = CAMERA_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_lightmode_status($status)
    {
        switch ($status) {
            case 'off':
                $val = LIGHT_STATUS_OFF;
                break;
            case 'on':
                $val = LIGHT_STATUS_ON;
                break;
            case 'auto':
                $val = LIGHT_STATUS_AUTO;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = LIGHT_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_sd_status($status)
    {
        switch ($status) {
            case 'off':
                $val = SDCARD_STATUS_OFF;
                break;
            case 'on':
                $val = SDCARD_STATUS_ON;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = SDCARD_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_alim_status($status)
    {
        switch ($status) {
            case 'off':
                $val = ALIM_STATUS_OFF;
                break;
            case 'on':
                $val = ALIM_STATUS_ON;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = ALIM_STATUS_UNDEFINED;
                break;
        }

        return $val;
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

    private function determineVpnUrl()
    {
        $vpn_url = $this->GetBuffer('vpn_url');
        return $vpn_url;
    }

    private function determineLocalUrl()
    {
        $is_local = $this->GetBuffer('is_local');
        if (!$is_local) {
            return false;
        }

        $local_url = $this->GetBuffer('local_url');
        if ($local_url != '') {
            return $local_url;
        }

        $vpn_url = $this->GetBuffer('vpn_url');
        if ($vpn_url == '') {
            return false;
        }

        $data = '';
        $err = '';

        $url = $vpn_url . '/command/ping';
        $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
        if ($statuscode == 0) {
            $response1 = json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'response1=' . print_r($response1, true), 0);
            $local_url1 = $this->GetArrayElem($response1, 'local_url', '');

            $url = $local_url1 . '/command/ping';
            $statuscode = $this->do_HttpRequest($url, '', '', 'GET', $data, $err);
            if ($statuscode == 0) {
                $response2 = json_decode($data, true);
                $this->SendDebug(__FUNCTION__, 'response2=' . print_r($response2, true), 0);
                $local_url2 = $this->GetArrayElem($response2, 'local_url', '');
                if ($local_url1 == $local_url2) {
                    $local_url = $local_url1;
                }
            }
        }

        $this->SetBuffer('local_url', $local_url);

        if ($statuscode) {
            $this->LogMessage('statuscode=' . $statuscode . ', err=' . $err, KL_WARNING);
            $this->SendDebug(__FUNCTION__, $err, 0);
            $this->SetStatus($statuscode);
            return false;
        }

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
}
