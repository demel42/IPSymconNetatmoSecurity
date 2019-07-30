<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen

class NetatmoSecurityConfig extends IPSModule
{
    use NetatmoSecurityCommon;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ImportCategoryID', 0);

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetStatus(IS_ACTIVE);
    }

    private function SetLocation()
    {
        $category = $this->ReadPropertyInteger('ImportCategoryID');
        $tree_position = [];
		if ($category > 0 && IPS_ObjectExists($category)) {
			$tree_position[] = IPS_GetName($category);
			$parent = IPS_GetObject($category)['ParentID'];
			while ($parent > 0) {
				if ($parent > 0) {
					$tree_position[] = IPS_GetName($parent);
				}
				$parent = IPS_GetObject($parent)['ParentID'];
			}
			$tree_position = array_reverse($tree_position);
		}
        return $tree_position;
    }

    private function buildEntry($guid, $product_type, $product_id, $product_name, $home_id, $home_name, $product_category)
    {
        $instID = 0;
        $instIDs = IPS_GetInstanceListByModuleID($guid);
        foreach ($instIDs as $id) {
            $prodID = IPS_GetProperty($id, 'product_id');
            if ($prodID == $product_id) {
                $instID = $id;
                break;
            }
        }

        $create = [
                    'moduleID'       => $guid,
                    'location'       => $this->SetLocation(),
                    'configuration'  => [
                            'product_type' => $product_type,
                            'product_id'   => $product_id,
                            'home_id'      => $home_id,
                        ]
                    ];
        if (IPS_GetKernelVersion() >= 5.1) {
            $create['info'] = $home_name . '\\' . $product_name;
        }

        $entry = [
                'category'   => $this->Translate($product_category),
                'home'       => $home_name,
                'name'       => $product_name,
                'product_id' => $product_id,
                'instanceID' => $instID,
                'create'     => $create,
            ];

        return $entry;
    }

    public function GetConfigurationForm()
    {
        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}', 'Function' => 'LastData'];
        $data = $this->SendDataToParent(json_encode($SendData));

        $this->SendDebug(__FUNCTION__, "data=$data", 0);

        $entries = [];
        if ($data != '') {
            $jdata = json_decode($data, true);
            $homes = $jdata['body']['homes'];
            foreach ($homes as $home) {
                $home_name = $home['name'];
                $home_id = $home['id'];
                if (isset($home['cameras'])) {
                    $cameras = $home['cameras'];
                    if ($cameras != '') {
                        foreach ($cameras as $camera) {
                            $product_id = $camera['id'];
                            $product_name = $camera['name'];
                            $product_type = $camera['type'];
                            switch ($product_type) {
                                case 'NACamera':
                                    $guid = '{06D589CF-7789-44B1-A0EC-6F51428352E6}';
                                    $product_category = 'Indoor camera';
                                    break;
                                case 'NOC':
                                    $guid = '{06D589CF-7789-44B1-A0EC-6F51428352E6}';
                                    $product_category = 'Outdoor camera';
                                    break;
                                default:
                                    $guid = '';
                                    break;
                            }
                            if ($guid == '') {
                                $this->SendDebug(__FUNCTION__, 'ignore camera ' . $camera['id'] . ': unsupported type ' . $camera['type']);
                                continue;
                            }

                            $entry = $this->buildEntry($guid, $product_type, $product_id, $product_name, $home_id, $home_name, $product_category);
                            $entries[] = $entry;
                        }
                    }
                }
                if (isset($home['smokedetectors'])) {
                    $smokedetectors = $home['smokedetectors'];
                    if ($smokedetectors != '') {
                        foreach ($smokedetectors as $smokedetector) {
                            $product_id = $smokedetector['id'];
                            $product_name = $smokedetector['name'];
                            $product_type = $smokedetector['type'];
                            switch ($product_type) {
                                case 'NSD':
                                    $guid = '';
                                    $product_category = 'Smoke detector';
                                    break;
                                default:
                                    $guid = '';
                                    break;
                            }
                            if ($guid == '') {
                                $this->SendDebug(__FUNCTION__, 'ignore smokedetector ' . $smokedetector['id'] . ': unsupported type ' . $smokedetector['type']);
                                continue;
                            }

                            $entry = $this->buildEntry($guid, $product_type, $product_id, $product_name, $home_id, $home_name, $product_category);
                            $entries[] = $entry;
                        }
                    }
                }
            }
        }

        $configurator = [
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

        $formElements = [];
        $formElements[] = ['type' => 'Label', 'caption' => 'category for products to be created:'];
        $formElements[] = ['name' => 'ImportCategoryID', 'type' => 'SelectCategory', 'caption' => 'category'];
        $formElements[] = $configurator;

        $formActions = [];
        $formActions[] = [
                            'type'    => 'Button',
                            'caption' => 'Module description',
                            'onClick' => 'echo "https://github.com/demel42/IPSymconNetatmoSecurity/blob/master/README.md";'
                        ];

        $formStatus = $this->GetFormStatus();

        $form = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
        if ($form == '') {
            $this->SendDebug(__FUNCTION__, 'json_error=' . json_last_error_msg(), 0);
            $this->SendDebug(__FUNCTION__, '=> formElements=' . print_r($formElements, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formActions=' . print_r($formActions, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formStatus=' . print_r($formStatus, true), 0);
        }
        return $form;
    }
}
