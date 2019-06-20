<?php

trait NetatmoSecurityLibrary
{
    private function map_camera_status($status)
    {
        switch ($status) {
            case 'off':
                $val = 0;
                break;
            case 'on':
                $val = 1;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = -1;
                break;
        }

        return $val;
    }

    private function map_sd_status($status)
    {
        switch ($status) {
            case 'off':
                $val = 0;
                break;
            case 'on':
                $val = 1;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = -1;
                break;
        }

        return $val;
    }

    private function map_alim_status($status)
    {
        switch ($status) {
            case 'off':
                $val = 0;
                break;
            case 'on':
                $val = 1;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = -1;
                break;
        }

        return $val;
    }

    private function map_lightmode_status($status)
    {
        switch ($status) {
            case 'off':
                $val = 0;
                break;
            case 'on':
                $val = 1;
                break;
            case 'auto':
                $val = 2;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = -1;
                break;
        }

        return $val;
    }
}
