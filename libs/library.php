<?php

if (!defined('CAMERA_STATUS_UNDEFINED')) {
    define('CAMERA_STATUS_UNDEFINED', -1);
    define('CAMERA_STATUS_OFF', 0);
    define('CAMERA_STATUS_ON', 1);
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

if (!defined('LIGHT_STATUS_UNDEFINED')) {
    define('LIGHT_STATUS_UNDEFINED', -1);
    define('LIGHT_STATUS_OFF', 0);
    define('LIGHT_STATUS_ON', 1);
    define('LIGHT_STATUS_AUTO', 2);
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
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = CAMERA_STATUS_UNDEFINED;
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
}
