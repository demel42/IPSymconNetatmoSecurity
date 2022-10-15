<?php

declare(strict_types=1);

trait NetatmoSecurityLocalLib
{
    public static $IS_NODATA = IS_EBASE + 10;
    public static $IS_UNAUTHORIZED = IS_EBASE + 11;
    public static $IS_FORBIDDEN = IS_EBASE + 12;
    public static $IS_SERVERERROR = IS_EBASE + 13;
    public static $IS_HTTPERROR = IS_EBASE + 14;
    public static $IS_INVALIDDATA = IS_EBASE + 15;
    public static $IS_NOPRODUCT = IS_EBASE + 16;
    public static $IS_PRODUCTMISSІNG = IS_EBASE + 17;
    public static $IS_NOWEBHOOK = IS_EBASE + 18;
    public static $IS_NOLOGIN = IS_EBASE + 19;

    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        $formStatus[] = ['code' => self::$IS_NODATA, 'icon' => 'error', 'caption' => 'Instance is inactive (no data)'];
        $formStatus[] = ['code' => self::$IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (unauthorized)'];
        $formStatus[] = ['code' => self::$IS_FORBIDDEN, 'icon' => 'error', 'caption' => 'Instance is inactive (forbidden)'];
        $formStatus[] = ['code' => self::$IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (server error)'];
        $formStatus[] = ['code' => self::$IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (http error)'];
        $formStatus[] = ['code' => self::$IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid data)'];
        $formStatus[] = ['code' => self::$IS_NOPRODUCT, 'icon' => 'error', 'caption' => 'Instance is inactive (no product)'];
        $formStatus[] = ['code' => self::$IS_PRODUCTMISSІNG, 'icon' => 'error', 'caption' => 'Instance is inactive (product missing)'];
        $formStatus[] = ['code' => self::$IS_NOWEBHOOK, 'icon' => 'error', 'caption' => 'Instance is inactive (webhook not given)'];
        $formStatus[] = ['code' => self::$IS_NOLOGIN, 'icon' => 'error', 'caption' => 'Instance is inactive (not logged in)'];

        return $formStatus;
    }

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

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

    public static $CONNECTION_UNDEFINED = 0;
    public static $CONNECTION_OAUTH = 1;
    public static $CONNECTION_DEVELOPER = 2;

    public static $WIFI_BAD = 0;
    public static $WIFI_AVERAGE = 1;
    public static $WIFI_GOOD = 2;
    public static $WIFI_HIGH = 3;

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

    public static $PRESENCE_ACTION_AWAY = 0;
    public static $PRESENCE_ACTION_HOME = 1;
    public static $PRESENCE_ACTION_ALLAWAY = 2;

    public static $MOTION_TYPE_NONE = 0;
    public static $MOTION_TYPE_MOVEMENT = 1;
    public static $MOTION_TYPE_HUMAN = 2;
    public static $MOTION_TYPE_PERSON = 3;
    public static $MOTION_TYPE_ANIMAL = 4;
    public static $MOTION_TYPE_VEHICLE = 5;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }

        $associations = [
            ['Wert' => self::$CAMERA_STATUS_UNDEFINED, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$CAMERA_STATUS_OFF, 'Name' => $this->Translate('off'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$CAMERA_STATUS_ON, 'Name' => $this->Translate('on'), 'Farbe' => -1],
            ['Wert' => self::$CAMERA_STATUS_DISCONNECTED, 'Name' => $this->Translate('disconnected'), 'Farbe' => 0xEE0000],
        ];
        $this->CreateVarProfile('NetatmoSecurity.CameraStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$CAMERA_STATUS_OFF, 'Name' => $this->Translate('off'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$CAMERA_STATUS_ON, 'Name' => $this->Translate('on'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('NetatmoSecurity.CameraAction', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$LIGHT_STATUS_UNDEFINED, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$LIGHT_STATUS_OFF, 'Name' => $this->Translate('off'), 'Farbe' => -1],
            ['Wert' => self::$LIGHT_STATUS_ON, 'Name' => $this->Translate('on'), 'Farbe' => -1],
            ['Wert' => self::$LIGHT_STATUS_AUTO, 'Name' => $this->Translate('auto'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('NetatmoSecurity.LightModeStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$LIGHT_STATUS_OFF, 'Name' => $this->Translate('off'), 'Farbe' => -1],
            ['Wert' => self::$LIGHT_STATUS_ON, 'Name' => $this->Translate('on'), 'Farbe' => -1],
            ['Wert' => self::$LIGHT_STATUS_AUTO, 'Name' => $this->Translate('auto'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('NetatmoSecurity.LightAction', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $this->CreateVarProfile('NetatmoSecurity.LightIntensity', VARIABLETYPE_INTEGER, ' %', 0, 100, 1, 0, '', [], $reInstall);

        $associations = [
            ['Wert' => self::$SDCARD_STATUS_UNDEFINED, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$SDCARD_STATUS_UNUSABLE, 'Name' => $this->Translate('unusable'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$SDCARD_STATUS_READY, 'Name' => $this->Translate('ready'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('NetatmoSecurity.SDCardStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$POWER_STATUS_UNDEFINED, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$POWER_STATUS_BAD, 'Name' => $this->Translate('bad'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$POWER_STATUS_GOOD, 'Name' => $this->Translate('good'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('NetatmoSecurity.PowerStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$WIFI_BAD, 'Name' => $this->Translate('bad'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$WIFI_AVERAGE, 'Name' => $this->Translate('average'), 'Farbe' => 0xFFFF00],
            ['Wert' => self::$WIFI_GOOD, 'Name' => $this->Translate('good'), 'Farbe' => 0x32CD32],
            ['Wert' => self::$WIFI_HIGH, 'Name' => $this->Translate('high'), 'Farbe' => 0x228B22],
        ];
        $this->CreateVarProfile('NetatmoSecurity.WifiStrength', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, 'Intensity', $associations, $reInstall);

        $associations = [
            ['Wert' => false, 'Name' => $this->Translate('absent'), 'Farbe' => 0xEE0000],
            ['Wert' => true, 'Name' => $this->Translate('present'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('NetatmoSecurity.Presence', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$PRESENCE_ACTION_AWAY, 'Name' => $this->Translate('away'), 'Farbe' => -1],
            ['Wert' => self::$PRESENCE_ACTION_HOME, 'Name' => $this->Translate('home'), 'Farbe' => -1],
            ['Wert' => self::$PRESENCE_ACTION_ALLAWAY, 'Name' => $this->Translate('all away'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('NetatmoSecurity.PresenceAction', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$MOTION_TYPE_NONE, 'Name' => $this->Translate('none'), 'Farbe' => -1],
            ['Wert' => self::$MOTION_TYPE_MOVEMENT, 'Name' => $this->Translate('movement'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$MOTION_TYPE_HUMAN, 'Name' => $this->Translate('person'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$MOTION_TYPE_PERSON, 'Name' => $this->Translate('known person'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$MOTION_TYPE_ANIMAL, 'Name' => $this->Translate('animal'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$MOTION_TYPE_VEHICLE, 'Name' => $this->Translate('vehicle'), 'Farbe' => 0xEE0000],
        ];
        $this->CreateVarProfile('NetatmoSecurity.MotionType', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);
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
