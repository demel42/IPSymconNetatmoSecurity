<?php

if (!defined('VARIABLETYPE_BOOLEAN')) {
    define('VARIABLETYPE_BOOLEAN', 0);
    define('VARIABLETYPE_INTEGER', 1);
    define('VARIABLETYPE_FLOAT', 2);
    define('VARIABLETYPE_STRING', 3);
}

if (!defined('MEDIATYPE_DOCUMENT')) {
    define('MEDIATYPE_DOCUMENT', 5);
}

if (!defined('IS_NODATA')) {
    define('IS_NODATA', IS_EBASE + 1);
    define('IS_UNAUTHORIZED', IS_EBASE + 2);
    define('IS_FORBIDDEN', IS_EBASE + 3);
    define('IS_SERVERERROR', IS_EBASE + 4);
    define('IS_HTTPERROR', IS_EBASE + 5);
    define('IS_INVALIDDATA', IS_EBASE + 6);
    define('IS_NOPRODUCT', IS_EBASE + 7);
    define('IS_PRODUCTMISSІNG', IS_EBASE + 8);
    define('IS_NOWEBHOOK', IS_EBASE + 9);
    define('IS_USEDWEBHOOK', IS_EBASE + 10);
    define('IS_INVALIDCONFIG', IS_EBASE + 11);
}

trait NetatmoSecurityCommon
{
    protected function SetValue($Ident, $Value)
    {
        @$varID = $this->GetIDForIdent($Ident);
        if ($varID == false) {
            $this->SendDebug(__FUNCTION__, 'missing variable ' . $Ident, 0);
            return;
        }

        @$ret = parent::SetValue($Ident, $Value);
        if ($ret == false) {
            $this->SendDebug(__FUNCTION__, 'mismatch of value "' . $Value . '" for variable ' . $Ident, 0);
        }
    }

    protected function GetValue($Ident)
    {
        @$varID = $this->GetIDForIdent($Ident);
        if ($varID == false) {
            $this->SendDebug(__FUNCTION__, 'missing variable ' . $Ident, 0);
            return false;
        }

        $ret = parent::GetValue($Ident);
        return $ret;
    }

    private function CreateVarProfile($Name, $ProfileType, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Icon, $Asscociations = '')
    {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, $ProfileType);
            IPS_SetVariableProfileText($Name, '', $Suffix);
            IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
            IPS_SetVariableProfileDigits($Name, $Digits);
            IPS_SetVariableProfileIcon($Name, $Icon);
            if ($Asscociations != '') {
                foreach ($Asscociations as $a) {
                    $w = isset($a['Wert']) ? $a['Wert'] : '';
                    $n = isset($a['Name']) ? $a['Name'] : '';
                    $i = isset($a['Icon']) ? $a['Icon'] : '';
                    $f = isset($a['Farbe']) ? $a['Farbe'] : 0;
                    IPS_SetVariableProfileAssociation($Name, $w, $n, $i, $f);
                }
            }
        }
    }

    private function GetMediaData($Name)
    {
        $mediaName = $this->Translate($Name);
        @$mediaID = IPS_GetMediaIDByName($mediaName, $this->InstanceID);
        if ($mediaID == false) {
            $this->SendDebug(__FUNCTION__, 'missing media-object ' . $Name, 0);
            return false;
        }
        $data = base64_decode(IPS_GetMediaContent($mediaID));
        return $data;
    }

    private function SetMediaData($Name, $data, $Cached)
    {
        $mediaName = $this->Translate($Name);
        @$mediaID = IPS_GetMediaIDByName($mediaName, $this->InstanceID);
        if ($mediaID == false) {
            $mediaID = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
            if ($mediaID == false) {
                $this->SendDebug(__FUNCTION__, 'unable to create media-object ' . $Name, 0);
                return false;
            }
            $filename = 'media' . DIRECTORY_SEPARATOR . $this->InstanceID . '-' . $Name . '.dat';
            IPS_SetMediaFile($mediaID, $filename, false);
            IPS_SetName($mediaID, $mediaName);
            IPS_SetParent($mediaID, $this->InstanceID);
        }
        IPS_SetMediaCached($mediaID, $Cached);
        IPS_SetMediaContent($mediaID, base64_encode($data));
    }

    // Inspired from module SymconTest/HookServe
    private function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    // Inspired from module SymconTest/HookServe
    private function GetMimeType($extension)
    {
        $lines = file(IPS_GetKernelDirEx() . 'mime.types');
        foreach ($lines as $line) {
            $type = explode("\t", $line, 2);
            if (count($type) == 2) {
                $types = explode(' ', trim($type[1]));
                foreach ($types as $ext) {
                    if ($ext == $extension) {
                        return $type[0];
                    }
                }
            }
        }
        return 'text/plain';
    }

    private function GetArrayElem($data, $var, $dflt)
    {
        $ret = $data;
        $vs = explode('.', $var);
        foreach ($vs as $v) {
            if (!isset($ret[$v])) {
                $ret = $dflt;
                break;
            }
            $ret = $ret[$v];
        }
        return $ret;
    }

    // inspired by Nall-chan
    //   https://github.com/Nall-chan/IPSSqueezeBox/blob/6bbdccc23a0de51bb3fbc114cefc3acf23c27a14/libs/SqueezeBoxTraits.php
    public function __get($name)
    {
        $n = strpos($name, 'Multi_');
        if (strpos($name, 'Multi_') === 0) {
            $curCount = $this->GetBuffer('BufferCount_' . $name);
            if ($curCount == false) {
                $curCount = 0;
            }
            $data = '';
            for ($i = 0; $i < $curCount; $i++) {
                $data .= $this->GetBuffer('BufferPart' . $i . '_' . $name);
            }
        } else {
            $data = $this->GetBuffer($name);
        }
        return unserialize($data);
    }

    public function __set($name, $value)
    {
        $data = serialize($value);
        $n = strpos($name, 'Multi_');
        if (strpos($name, 'Multi_') === 0) {
            $oldCount = $this->GetBuffer('BufferCount_' . $name);
            if ($oldCount == false) {
                $oldCount = 0;
            }
            $parts = str_split($data, 8000);
            $newCount = count($parts);
            $this->SetBuffer('BufferCount_' . $name, $newCount);
            for ($i = 0; $i < $newCount; $i++) {
                $this->SetBuffer('BufferPart' . $i . '_' . $name, $parts[$i]);
            }
            for ($i = $newCount; $i < $oldCount; $i++) {
                $this->SetBuffer('BufferPart' . $i . '_' . $name, '');
            }
        } else {
            $this->SetBuffer($name, $data);
        }
    }

    private function SetMultiBuffer($name, $value)
    {
        $this->{'Multi_' . $name} = $value;
    }

    private function GetMultiBuffer($name)
    {
        $value = $this->{'Multi_' . $name};
        return $value;
    }

    private function bool2str($bval)
    {
        if (is_bool($bval)) {
            return $bval ? 'true' : 'false';
        }
        return $bval;
    }

    protected function GetStatus()
    {
        if (IPS_GetKernelVersion() >= 5.1) {
            return parent::GetStatus();
        }

        $inst = IPS_GetInstance($this->InstanceID);
        return $inst['InstanceStatus'];
    }

	private function GetConnectUrl()
	{
		$instID = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
		if (IPS_GetKernelVersion() >= 5.2) {
			$url = CC_GetConnectURL($instID);
		} else {
			$url = CC_GetUrl($instID);
		}
		return $url;
	}

    private function HookIsUsed($newHook)
    {
        $used = false;
        $instID = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}')[0];
        $hooks = json_decode(IPS_GetProperty($instID, 'Hooks'), true);
        foreach ($hooks as $hook) {
            if ($hook['Hook'] == $newHook) {
                if ($hook['TargetID'] != $this->InstanceID) {
                    $used = true;
                }
                break;
            }
        }
        return $used;
    }

    private function GetFormStatus()
    {
        $formStatus = [];
        $formStatus[] = ['code' => IS_CREATING, 'icon' => 'inactive', 'caption' => 'Instance getting created'];
        $formStatus[] = ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => 'Instance is active'];
        $formStatus[] = ['code' => IS_DELETING, 'icon' => 'inactive', 'caption' => 'Instance is deleted'];
        $formStatus[] = ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => 'Instance is inactive'];
        $formStatus[] = ['code' => IS_NOTCREATED, 'icon' => 'inactive', 'caption' => 'Instance is not created'];

        $formStatus[] = ['code' => IS_NODATA, 'icon' => 'error', 'caption' => 'Instance is inactive (no data)'];
        $formStatus[] = ['code' => IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (unauthorized)'];
        $formStatus[] = ['code' => IS_FORBIDDEN, 'icon' => 'error', 'caption' => 'Instance is inactive (forbidden)'];
        $formStatus[] = ['code' => IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (server error)'];
        $formStatus[] = ['code' => IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (http error)'];
        $formStatus[] = ['code' => IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid data)'];
        $formStatus[] = ['code' => IS_NOPRODUCT, 'icon' => 'error', 'caption' => 'Instance is inactive (no product)'];
        $formStatus[] = ['code' => IS_PRODUCTMISSІNG, 'icon' => 'error', 'caption' => 'Instance is inactive (product missing)'];
        $formStatus[] = ['code' => IS_NOWEBHOOK, 'icon' => 'error', 'caption' => 'Instance is inactive (webhook not given)'];
        $formStatus[] = ['code' => IS_USEDWEBHOOK, 'icon' => 'error', 'caption' => 'Instance is inactive (webhook already in use)'];
        $formStatus[] = ['code' => IS_INVALIDCONFIG, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid config)'];

        return $formStatus;
    }
}
