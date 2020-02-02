<?php

declare(strict_types=1);

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
    define('SDCARD_STATUS_UNUSABLE', 0);
    define('SDCARD_STATUS_READY', 1);
}

if (!defined('POWER_STATUS_UNDEFINED')) {
    define('POWER_STATUS_UNDEFINED', -1);
    define('POWER_STATUS_BAD', 0);
    define('POWER_STATUS_GOOD', 1);
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
                $val = SDCARD_STATUS_UNUSABLE;
                break;
            case 'on':
                $val = SDCARD_STATUS_READY;
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

    private function map_power_status($status)
    {
        switch ($status) {
            case 'off':
                $val = POWER_STATUS_BAD;
                break;
            case 'on':
                $val = POWER_STATUS_GOOD;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = POWER_STATUS_UNDEFINED;
                break;
        }

        return $val;
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
