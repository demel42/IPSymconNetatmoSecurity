<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen

class NetatmoSecurityConfig extends IPSModule
{
    use NetatmoSecurityCommon;

    public function Create()
    {
        parent::Create();

        $this->ConnectParent('{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm()
    {
        $SendData = ['DataID' => '{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}'];
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
									$guid = '';
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
                                $this->SendDebug(__FUNCTION__, 'ignore camera '. $camera['id'] . ': unsupported type ' . $camera['type']);
                                continue;
							}

							$instID = 0;
							$instIDs = IPS_GetInstanceListByModuleID($guid);
							foreach ($instIDs as $id) {
								$prodID = IPS_GetProperty ($id, 'product_id');
								if ($prodID == $product_id) {
									$instID = $id;
									break;
								}
							}

							$create = [
										'moduleID'       => $guid,
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
							$product_type = 'Smoke detector';
							$guid = '';
							switch ($smokedetector['type']) {
								case 'NSD':
									$guid = '';
									$product_category = 'Smoke detector';
									break;
								default:
									break;
							}
							if ($guid == '') {
                                $this->SendDebug(__FUNCTION__, 'ignore smokedetector '. $smokedetector['id'] . ': unsupported type ' . $smokedetector['type']);
                                continue;
                            }
                        }
                    }
                }
            }
        }

		$configurator = [
			'type' => 'Configurator',
			'name' => 'products',
			'caption' => 'Products',

			'rowCount' => count($entries),

			'add' => false,
			'delete' => false,
			'sort' => [
				'column' => 'name',
				'direction' => 'ascending'
			],
			'columns' => [
				[
					'caption' => 'Category',
					'name' => 'category',
					'width' => '200px',
				],
				[
					'caption' => 'Home',
					'name' => 'home',
					'width' => '200px'
				],
				[
					'caption' => 'Name',
					'name' => 'name',
					'width' => 'auto'
				],
				[
					'caption' => 'Id',
					'name' => 'product_id',
					'width' => '200px'
				]
			],
			'values' => $entries,
		];

        $formActions = [];
        $formActions[] = $configurator;
		$formActions[] = ['type' => 'Label', 'label' => '____________________________________________________________________________________________________'];
        $formActions[] = [
                            'type'    => 'Button',
                            'caption' => 'Module description',
                            'onClick' => 'echo "https://github.com/demel42/IPSymconNetatmoSecurity/blob/master/README.md";'
                        ];

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

        return json_encode(['actions' => $formActions, 'status' => $formStatus]);
    }
}
