<?php

namespace LiamW\XenForoUpgrade\XF\Admin\Controller;

use LiamW\XenForoUpgrade\Service\XFUpgrade;

class Tools extends XFCP_Tools
{
	public function actionUpgrade()
	{
		$this->assertCanUpgrade();

		return $this->view('LiamW\XenForoUpgrade:Upgrade\Index', 'liamw_xenforoupgrade_index');
	}

	public function actionUpgradeCredentials()
	{
		$confirmed = $this->filter('confirmed', 'bool');

		if (!$confirmed)
		{
			$this->redirect($this->buildLink('tools/upgrade'));
		}

		return $this->view('LiamW\XenForoUpgrade:Upgrade\Credentials', 'liamw_xenforoupgrade_credentials');
	}

	public function actionUpgradeLicense()
	{
		$this->assertPostOnly();

		$data = $this->filter([
			'email' => 'str', 'password' => 'str'
		]);

		if (!$data['email'] || !$data['password'])
		{
			return $this->error(\XF::phrase('liam_xenforoupdater_invalid_credentials'));
		}

		/** @var \LiamW\XenForoUpgrade\Service\XFUpgrade $upgradeService */
		$upgradeService = $this->service('LiamW\XenForoUpgrade:XFUpgrade');
		$licenses = $upgradeService->setLoginDetails($data['email'], $data['password'])->getLicenses();

		if (!$licenses)
		{
			return $this->error(\XF::phrase('liamw_xenforoupgrade_no_licenses_or_incorrect_credentials'));
		}

		$viewParams = [
			'licenses' => $licenses
		];

		return $this->view('LiamW\XenForoUpgrade:Upgrade\License', 'liamw_xenforoupgrade_license', $viewParams);
	}

	public function actionUpgradeProduct()
	{
		$this->assertPostOnly();

		$licenseId = $this->filter('license_id', 'str');

		/** @var \LiamW\XenForoUpgrade\Service\XFUpgrade $upgradeService */
		$upgradeService = $this->service('LiamW\XenForoUpgrade:XFUpgrade');

		$availableProducts = $upgradeService->load()->getAvailableProductsForLicense($licenseId);

		$availableProductsReturn = [];
		foreach ($availableProducts as $availableProduct)
		{
			$availableProductsReturn[$availableProduct] = \XF::phrase('liamw_xenforoupgrade_update_' . $availableProduct);
		}

		$viewParams = [
			'availableProducts' => $availableProductsReturn
		];

		return $this->view('LiamW\XenForoUpgrade:Upgrade\Product', 'liamw_xenforoupgrade_product', $viewParams);
	}

	public function actionUpgradeVersion()
	{
		$data = $this->filter([
			'product_id' => 'str'
		]);

		$this->assertValidProduct($data['product_id']);

		/** @var \LiamW\XenForoUpgrade\Service\XFUpgrade $upgradeService */
		$upgradeService = $this->service('LiamW\XenForoUpgrade:XFUpgrade');
		$downloadVersions = $upgradeService->load()->setSelectedProduct($data['product_id'])
			->getAvailableVersions($selectedVersion);

		$viewParams = [
			'versions' => $downloadVersions,
			'selectedVersion' => $selectedVersion
		];

		$viewParams['productName'] = \XF::phrase('liamw_xenforoupgrade_update_' . $upgradeService->getSelectedProduct());

		return $this->view('LiamW\XenForoUpgrade:Upgrade\Version', 'liamw_xenforoupgrade_version', $viewParams);
	}

	public function actionUpgradeUpgrade()
	{
		$this->assertPostOnly();

		$versionId = $this->filter('version_id', 'uint');

		/** @var \LiamW\XenForoUpgrade\Service\XFUpgrade $upgradeService */
		$upgradeService = $this->service('LiamW\XenForoUpgrade:XFUpgrade')->load();

		try
		{
			$upgradeService->setSelectedVersion($versionId);
		} catch (\InvalidArgumentException $e)
		{
			return $this->error(\XF::phrase('liamw_xenforoupgrade_please_select_a_valid_version'));
		}

		$ftpData = $this->filter([
			'ftp_upload' => 'bool', 'host' => 'str', 'port' => 'uint',
			'user' => 'str', 'password' => 'str', 'ssl' => 'bool',
			'xf_path' => 'str'
		]);

		$upgradeService->setFtpData($ftpData);

		$result = $upgradeService->doUpgrade();
	}

	protected function assertCanUpgrade()
	{
		$this->assertAdminPermission('upgrade');
	}

	protected function assertValidProduct($product)
	{
		switch ($product)
		{
			case XFUpgrade::PRODUCT_XENFORO:
			case XFUpgrade::PRODUCT_RESOURCE_MANAGER:
			case XFUpgrade::PRODUCT_MEDIA_GALLERY:
			case XFUpgrade::PRODUCT_ENHANCED_SEARCH:
				return;
		}

		throw $this->exception($this->error(\XF::phrase('liam_xenforoupdater_invalid_product')));
	}
}