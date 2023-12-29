<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class NetatmoSecurityConfig extends IPSModule
{
    use NetatmoSecurity\StubsCommonLib;
    use NetatmoSecurityLocalLib;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonContruct(__DIR__);
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        if (IPS_GetKernelVersion() < 7.0) {
            $this->RegisterPropertyInteger('ImportCategoryID', 0);
        }

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = [];
        if (IPS_GetKernelVersion() < 7.0) {
            $propertyNames[] = 'ImportCategoryID';
        }
        $this->MaintainReferences($propertyNames);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);
    }

    private function getConfiguratorValues()
    {
        $entries = [];

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return $entries;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            return $entries;
        }

        if (IPS_GetKernelVersion() < 7.0) {
            $catID = $this->ReadPropertyInteger('ImportCategoryID');
            $location = $this->GetConfiguratorLocation($catID);
        } else {
            $location = '';
        }

        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'LastData'];
        $data = $this->SendDataToParent(json_encode($SendData));
        $jdata = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

        if (is_array($jdata)) {
            $homes = $this->GetArrayElem($jdata, 'config.homes', '');
            foreach ($homes as $home) {
                $this->SendDebug(__FUNCTION__, 'home=' . print_r($home, true), 0);
                if (!isset($home['id'])) {
                    continue;
                }
                $home_id = $home['id'];
                $home_name = $this->GetArrayElem($home, 'name', 'ID:' . $home_id);
                if (isset($home['modules'])) {
                    $modules = $home['modules'];
                    if (is_array($modules)) {
                        foreach ($modules as $module) {
                            $product_id = $module['id'];
                            $product_name = $module['name'];
                            $product_type = $module['type'];
                            switch ($product_type) {
                                case 'NACamera':
                                    $guid = '{06D589CF-7789-44B1-A0EC-6F51428352E6}';
                                    $product_category = 'Indoor camera';
                                    break;
                                case 'NOC':
                                    $guid = '{06D589CF-7789-44B1-A0EC-6F51428352E6}';
                                    $product_category = 'Outdoor camera';
                                    break;
                                case 'NDB':
                                    $guid = '{06D589CF-7789-44B1-A0EC-6F51428352E6}';
                                    $product_category = 'Video doorbell';
                                    break;
                                case 'NSD':
                                    $guid = '{1E90911D-AB28-5EA7-9134-CCEAF7F48C78}';
                                    $product_category = 'Smoke detector';
                                    break;
                                case 'NCO':
                                    $guid = '{1E90911D-AB28-5EA7-9134-CCEAF7F48C78}';
                                    $product_category = 'Carbon monoxide detector';
                                    break;
                                default:
                                    $guid = '';
                                    break;
                            }
                            if ($guid == '') {
                                $this->SendDebug(__FUNCTION__, 'ignore module ' . $module['id'] . ': unsupported type ' . $module['type'], 0);
                                continue;
                            }

                            $instIDs = IPS_GetInstanceListByModuleID($guid);

                            $instanceID = 0;
                            foreach ($instIDs as $instID) {
                                $prodID = IPS_GetProperty($instID, 'product_id');
                                if ($prodID == $product_id) {
                                    $instanceID = $instID;
                                    break;
                                }
                            }

                            if ($instanceID && IPS_GetInstance($instanceID)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                                continue;
                            }

                            $entry = [
                                'instanceID' => $instanceID,
                                'category'   => $this->Translate($product_category),
                                'home'       => $home_name,
                                'name'       => $product_name,
                                'product_id' => $product_id,
                                'create'     => [
                                    'moduleID'       => $guid,
                                    'location'       => $location,
                                    'info'           => $home_name . '\\' . $product_name,
                                    'configuration'  => [
                                        'product_type' => $product_type,
                                        'product_id'   => $product_id,
                                        'home_id'      => $home_id,
                                    ]
                                ]
                            ];
                            $entries[] = $entry;
                            $this->SendDebug(__FUNCTION__, 'entry=' . print_r($entry, true), 0);
                        }
                    }
                }
            }
        }

        $modules = [
            [
                'category' => 'Camera',
                'guid'     => '{06D589CF-7789-44B1-A0EC-6F51428352E6}',
            ],
            [
                'category' => 'Detector',
                'guid'     => '{1E90911D-AB28-5EA7-9134-CCEAF7F48C78}',
            ],
        ];
        foreach ($modules as $module) {
            $category = $this->Translate($module['category']);
            $instIDs = IPS_GetInstanceListByModuleID($module['guid']);
            foreach ($instIDs as $instID) {
                $fnd = false;
                foreach ($entries as $entry) {
                    if ($entry['instanceID'] == $instID) {
                        $fnd = true;
                        break;
                    }
                }
                if ($fnd) {
                    continue;
                }

                if (IPS_GetInstance($instID)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                    continue;
                }

                $product_name = IPS_GetName($instID);
                $home_name = '';
                $product_id = IPS_GetProperty($instID, 'product_id');

                $entry = [
                    'instanceID' => $instID,
                    'category'   => $category,
                    'home'       => $home_name,
                    'name'       => $product_name,
                    'product_id' => $product_id,
                ];
                $entries[] = $entry;
                $this->SendDebug(__FUNCTION__, 'missing entry=' . print_r($entry, true), 0);
            }
        }

        return $entries;
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Netatmo Security Configurator');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        if (IPS_GetKernelVersion() < 7.0) {
            $formElements[] = [
                'type'    => 'SelectCategory',
                'name'    => 'ImportCategoryID',
                'caption' => 'category for products to be created'
            ];
        }

        $entries = $this->getConfiguratorValues();
        $formElements[] = [
            'type'    => 'Configurator',
            'name'    => 'products',
            'caption' => 'Products',

            'rowCount' => count($entries),

            'add'    => false,
            'delete' => false,
            'sort'   => [
                'column'    => 'name',
                'direction' => 'ascending'
            ],
            'columns' => [
                [
                    'caption' => 'Category',
                    'name'    => 'category',
                    'width'   => '200px',
                ],
                [
                    'caption' => 'Home',
                    'name'    => 'home',
                    'width'   => '200px'
                ],
                [
                    'caption' => 'Name',
                    'name'    => 'name',
                    'width'   => 'auto'
                ],
                [
                    'caption' => 'Id',
                    'name'    => 'product_id',
                    'width'   => '200px'
                ]
            ],
            'values' => $entries,
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }
}
