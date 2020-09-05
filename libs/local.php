<?php

declare(strict_types=1);

trait NetatmoSecurityLocalLib
{
    public static $IS_NODATA = IS_EBASE + 1;
    public static $IS_UNAUTHORIZED = IS_EBASE + 2;
    public static $IS_FORBIDDEN = IS_EBASE + 3;
    public static $IS_SERVERERROR = IS_EBASE + 4;
    public static $IS_HTTPERROR = IS_EBASE + 5;
    public static $IS_INVALIDDATA = IS_EBASE + 6;
    public static $IS_NOPRODUCT = IS_EBASE + 7;
    public static $IS_PRODUCTMISSІNG = IS_EBASE + 8;
    public static $IS_NOWEBHOOK = IS_EBASE + 9;
    public static $IS_USEDWEBHOOK = IS_EBASE + 10;
    public static $IS_INVALIDCONFIG = IS_EBASE + 11;
    public static $IS_NOSYMCONCONNECT = IS_EBASE + 12;
    public static $IS_NOLOGIN = IS_EBASE + 13;

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

    public static $CONNECTION_UNDEFINED = 0;
    public static $CONNECTION_OAUTH = 1;
    public static $CONNECTION_DEVELOPER = 2;

    public static $CAMERA_STATUS_UNDEFINED = -1;
    public static $CAMERA_STATUS_OFF = 0;
    public static $CAMERA_STATUS_ON = 1;
    public static $CAMERA_STATUS_DISCONNECTED = 2;

    public static $LIGHT_STATUS_UNDEFINED = -1;
    public static $LIGHT_STATUS_OFF = 0;
    public static $LIGHT_STATUS_ON = 1;
    public static $LIGHT_STATUS_AUTO = 2;

    public static $SDCARD_STATUS_UNDEFINED = -1;
    public static $SDCARD_STATUS_UNUSABLE = 0;
    public static $SDCARD_STATUS_READY = 1;

    public static $POWER_STATUS_UNDEFINED = -1;
    public static $POWER_STATUS_BAD = 0;
    public static $POWER_STATUS_GOOD = 1;

    private function GetFormStatus()
    {
        $formStatus = [];
        $formStatus[] = ['code' => IS_CREATING, 'icon' => 'inactive', 'caption' => 'Instance getting created'];
        $formStatus[] = ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => 'Instance is active'];
        $formStatus[] = ['code' => IS_DELETING, 'icon' => 'inactive', 'caption' => 'Instance is deleted'];
        $formStatus[] = ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => 'Instance is inactive'];
        $formStatus[] = ['code' => IS_NOTCREATED, 'icon' => 'inactive', 'caption' => 'Instance is not created'];

        $formStatus[] = ['code' => self::$IS_NODATA, 'icon' => 'error', 'caption' => 'Instance is inactive (no data)'];
        $formStatus[] = ['code' => self::$IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (unauthorized)'];
        $formStatus[] = ['code' => self::$IS_FORBIDDEN, 'icon' => 'error', 'caption' => 'Instance is inactive (forbidden)'];
        $formStatus[] = ['code' => self::$IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (server error)'];
        $formStatus[] = ['code' => self::$IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (http error)'];
        $formStatus[] = ['code' => self::$IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid data)'];
        $formStatus[] = ['code' => self::$IS_NOPRODUCT, 'icon' => 'error', 'caption' => 'Instance is inactive (no product)'];
        $formStatus[] = ['code' => self::$IS_PRODUCTMISSІNG, 'icon' => 'error', 'caption' => 'Instance is inactive (product missing)'];
        $formStatus[] = ['code' => self::$IS_NOWEBHOOK, 'icon' => 'error', 'caption' => 'Instance is inactive (webhook not given)'];
        $formStatus[] = ['code' => self::$IS_USEDWEBHOOK, 'icon' => 'error', 'caption' => 'Instance is inactive (webhook already in use)'];
        $formStatus[] = ['code' => self::$IS_INVALIDCONFIG, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid config)'];
        $formStatus[] = ['code' => self::$IS_NOSYMCONCONNECT, 'icon' => 'error', 'caption' => 'Instance is inactive (no Symcon-Connect)'];
        $formStatus[] = ['code' => self::$IS_NOLOGIN, 'icon' => 'error', 'caption' => 'Instance is inactive (not logged in)'];

        return $formStatus;
    }

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            case self::$IS_NODATA:
            case self::$IS_UNAUTHORIZED:
            case self::$IS_FORBIDDEN:
            case self::$IS_SERVERERROR:
            case self::$IS_HTTPERROR:
            case self::$IS_INVALIDDATA:
                $class = self::$STATUS_RETRYABLE;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    private function map_camera_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$CAMERA_STATUS_OFF;
                break;
            case 'on':
                $val = self::$CAMERA_STATUS_ON;
                break;
            case 'disconnected':
                $val = self::$CAMERA_STATUS_DISCONNECTED;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$CAMERA_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_lightmode_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$LIGHT_STATUS_OFF;
                break;
            case 'on':
                $val = self::$LIGHT_STATUS_ON;
                break;
            case 'auto':
                $val = self::$LIGHT_STATUS_AUTO;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$LIGHT_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_sd_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$SDCARD_STATUS_UNUSABLE;
                break;
            case 'on':
                $val = self::$SDCARD_STATUS_READY;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$SDCARD_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function map_power_status($status)
    {
        switch ($status) {
            case 'off':
                $val = self::$POWER_STATUS_BAD;
                break;
            case 'on':
                $val = self::$POWER_STATUS_GOOD;
                break;
            default:
                $e = 'unknown state "' . $status . '"';
                $this->SendDebug(__FUNCTION__, $e, 0);
                $this->LogMessage(__FUNCTION__ . ': ' . $e, KL_NOTIFY);
                $val = self::$POWER_STATUS_UNDEFINED;
                break;
        }

        return $val;
    }

    private function determineVpnUrl()
    {
        $vpn_url = $this->GetBuffer('vpn_url');
        $this->SendDebug(__FUNCTION__, 'vpn_url=' . $vpn_url, 0);
        return $vpn_url;
    }

    private function determineLocalUrl()
    {
        $is_local = $this->GetBuffer('is_local');
        $this->SendDebug(__FUNCTION__, 'is_local=' . $is_local, 0);
        if (!$is_local) {
            return false;
        }

        $local_url = $this->GetBuffer('local_url');
        $this->SendDebug(__FUNCTION__, 'local_url=' . $local_url, 0);
        if ($local_url != '') {
            return $local_url;
        }

        $vpn_url = $this->GetBuffer('vpn_url');
        $this->SendDebug(__FUNCTION__, 'vpn_url=' . $vpn_url, 0);
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
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } elseif ($httpcode != 200) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_FORBIDDEN;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode == 406) {
                if (preg_match('#^https://api.netatmo.net/api/dropwebhook#', $url)) {
                    $data = $cdata;
                } else {
                    $statuscode = self::$IS_HTTPERROR;
                    $err = 'got http-code ' . $httpcode . ' (not acceptable)';
                }
            } elseif ($httpcode == 409) {
                $data = $cdata;
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } else {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        } elseif ($cdata == '') {
            $statuscode = self::$IS_NODATA;
            $err = 'no data';
        } else {
            $jdata = json_decode($cdata, true);
            if ($jdata == '') {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'malformed response';
            } else {
                $data = $cdata;
            }
        }

        $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
        $this->SendDebug(__FUNCTION__, '    data=' . $data, 0);
        return $statuscode;
    }
}
