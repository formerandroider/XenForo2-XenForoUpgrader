<?php

namespace LiamW\XenForoUpgrade\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use League\Flysystem\Filesystem;
use League\Flysystem\ZipArchive\ZipArchiveAdapter as Zip;
use League\Flysystem\Adapter\Ftp;
use Symfony\Component\DomCrawler\Crawler;
use XF\Service\AbstractService;
use XF\Util\File;

class XFUpgrade extends AbstractService
{
	const LOGIN_URL = 'https://xenforo.com/customers/login';
	const CUSTOMER_URL = 'https://xenforo.com/customers';
	const DOWNLOAD_URL = 'https://xenforo.com/customers/download';
	const CUSTOMER_REDIRECT = 'customers';

	const USER_AGENT = 'XenForo Updater (Liam W)';

	const PRODUCT_XENFORO = 'xenforo';
	const PRODUCT_RESOURCE_MANAGER = 'xfresource';
	const PRODUCT_MEDIA_GALLERY = 'xfmg';
	const PRODUCT_ENHANCED_SEARCH = 'xfes';

	/** @var  CookieJar */
	protected $cookieJar;

	/** @var  Client */
	protected $httpClient;

	protected $ftpData;
	protected $ftpUpload;

	protected $email;
	protected $password;

	protected $availableProducts;
	protected $availableVersions;

	protected $selectedLicense;
	protected $selectedProduct;
	protected $selectedVersion;

	protected function setup()
	{
		$this->createHttpClient();
		$this->createCookieJar();

		\XF::runLater(function ()
		{
			$this->save();
		});
	}

	/**
	 * Load saved upgrade data from the session.
	 *
	 * @return \LiamW\XenForoUpgrade\Service\XFUpgrade
	 */
	public function load()
	{
		$data = \XF::app()->session()->get('xenforoUpgradeService');

		$this->setCookies($data['cookieJar']);
		$this->email = $data['email'];
		$this->password = $data['password'];
		$this->availableProducts = $data['availableProducts'];
		$this->availableVersions = $data['availableVersions'];

		$this->selectedLicense = $data['selectedLicense'];
		$this->selectedProduct = $data['selectedProduct'];
		$this->selectedVersion = $data['selectedVersion'];

		return $this;
	}

	/**
	 * @return array
	 */
	public function getCookies()
	{
		return $this->cookieJar->toArray();
	}

	/**
	 * @param array|CookieJar $cookies
	 *
	 * @return XFUpgrade
	 */
	public function setCookies($cookies)
	{
		if ($cookies instanceof CookieJar)
		{
			$this->cookieJar = $cookies;
		}
		else if (is_array($cookies))
		{
			$this->createCookieJar($cookies);
		}
		else
		{
			throw new \InvalidArgumentException('$cookies must be either an array or a CookieJar');
		}

		return $this;
	}

	public function setLoginDetails($email, $password)
	{
		$this->email = $email;
		$this->password = $password;

		return $this;
	}

	/**
	 * @param string $selectedLicense
	 *
	 * @return XFUpgrade
	 */
	public function setSelectedLicense($selectedLicense)
	{
		$this->selectedLicense = $selectedLicense;

		return $this;
	}

	/**
	 * @param string $selectedProduct
	 *
	 * @return XFUpgrade
	 */
	public function setSelectedProduct($selectedProduct)
	{
		if (!in_array($selectedProduct, $this->availableProducts[$this->selectedLicense]))
		{
			throw new \InvalidArgumentException("The selected product must be available (in the available products array).");
		}

		$this->selectedProduct = $selectedProduct;

		return $this;
	}

	/**
	 * @param int $selectedVersion
	 *
	 * @return XFUpgrade
	 */
	public function setSelectedVersion($selectedVersion)
	{
		if (!in_array($selectedVersion, $this->availableVersions))
		{
			throw new \InvalidArgumentException("The selected version must be available (in the available versions array).");
		}

		$this->selectedVersion = $selectedVersion;

		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getSelectedProduct()
	{
		return $this->selectedProduct;
	}

	/**
	 * @param mixed $ftpData
	 *
	 * @return XFUpgrade
	 */
	public function setFtpData($ftpData)
	{
		$data = [
			'host' => $ftpData['host'] ?: '127.0.0.1',
			'port' => $ftpData['port'] ?: 21,
			'username' => $ftpData['user'],
			'password' => $ftpData['password'],
			'root' => $ftpData['xf_path'],
			'ssl' => $ftpData['ssl']
		];

		$this->ftpData = $data;

		if ($ftpData['ftp_upload'])
		{
			$this->setFtpUpload(true);
		}

		return $this;
	}

	/**
	 * @param bool $ftpUpload
	 *
	 * @return XFUpgrade
	 */
	public function setFtpUpload($ftpUpload)
	{
		$this->ftpUpload = $ftpUpload;

		return $this;
	}

	public function getLicenses()
	{
		$licensesPage = $this->login(['redirect' => self::CUSTOMER_REDIRECT]);

		$page = new Crawler($licensesPage);

		$licenses = [];

		/** @var \DOMElement $license */
		foreach ($page->filter(".licenses .license") as $license)
		{
			$licenseId = false;

			$downloadLinks = $license->lastChild->getElementsByTagName('a');

			if (!$downloadLinks)
			{
				continue;
			}

			foreach ($downloadLinks as $downloadLink)
			{
				$href = $downloadLink->attributes->getNamedItem('href')->textContent;

				// Download link in format: customers/download/?l=<license_id>&d=<product>
				// (Mike, Chris, Kier... If you're reading this please don't change the structure)
				$regex = sprintf('#^.*\?l\=([A-Z0-9]+)\&d=([a-z]+).*$#');
				if (preg_match($regex, $href, $matches))
				{
					$licenseId = $matches[1];

					$this->availableProducts[$licenseId][] = $matches[2];
				}
			}

			$anchors = $license->childNodes->item(1)->childNodes->item(1)->getElementsByTagName('a');

			if (!$anchors->length)
			{
				// License hasn't been named - isn't valid.
				continue;
			}

			$licenseTitle = $anchors->item(0)->nodeValue;

			$licenses[$licenseId] = ['title' => $licenseTitle, 'selected' => stripos(\XF::options()->boardTitle, $licenseTitle)];
		}

		return $licenses;
	}

	public function getAvailableProductsForLicense($licenseId)
	{
		if (!isset($this->availableProducts[$licenseId]))
		{
			return [];
		}

		$this->setSelectedLicense($licenseId);

		return $this->availableProducts[$licenseId];
	}

	public function getAvailableVersions(&$selectedVersion)
	{
		$r = $this->getNewGuzzleRequest(self::DOWNLOAD_URL, "GET");

		$r->setQuery([
			'l' => $this->selectedLicense,
			'd' => $this->selectedProduct
		]);

		$downloadForm = $this->httpClient->send($r);
		$downloadFormCrawler = new Crawler($downloadForm->getBody()->getContents());

		$versions = $downloadFormCrawler->filter('select[name~="download_version_id"] option');

		if (!$versions->count())
		{
			return false;
		}

		$downloadVersions = [];

		/** @var \DOMElement $version */
		foreach ($versions as $version)
		{
			$downloadVersions[$version->getAttribute('value')] = $version->textContent;

			if ($version->getAttribute('selected') == 'selected' && ($this->selectedProduct != self::PRODUCT_XENFORO || substr(\XF::$versionId, 5, 1) > 7))
			{
				$selectedVersion = $version->getAttribute('value');
			}
		}

		krsort($downloadVersions);

		$this->availableVersions = array_keys($downloadVersions);

		return $downloadVersions;
	}

	public function doUpgrade()
	{
		@set_time_limit(0);
		@ignore_user_abort(true);

		$tmpFile = File::getTempFile();

		$r = $this->getNewGuzzleRequest(self::DOWNLOAD_URL, "POST", [
			'agree' => 1,
			'l' => $this->selectedLicense,
			'd' => $this->selectedProduct,
			'download_version_id' => $this->selectedVersion,
			'options[upgradePackage]' => $this->selectedProduct === self::PRODUCT_XENFORO
		], [
			'save_to' => $tmpFile
		]);

		$this->httpClient->send($r);

		$file = "internal-data://xf-upgrades/" . $this->selectedVersion . '.zip';
		File::copyFileToAbstractedPath($tmpFile, $file);

		$zip = new Zip($tmpFile);
		$zipFs = new Filesystem($zip);

		$fs = \XF::fs();

		$pathPrefix = "internal-data://xf-upgrades/" . $this->selectedVersion . "/";

		// This works but it unworkably slow, so...
		// TODO: Improve this!!!!!

		foreach ($zipFs->listContents('upload', true) AS $content)
		{
			if ($content['type'] == 'dir') {
				continue;
			}

			$path = $pathPrefix . substr($content['path'], 7);

			$fs->putStream($path, $zipFs->readStream($content['path']));
		}

		if ($this->ftpUpload)
		{
			$ftp = new Ftp($this->ftpData);
			$ftpFs = new Filesystem($ftp);
		}

		$zip->extractTo();

	}

	/**
	 * @param array $additionalParams
	 *
	 * @return bool|string
	 */
	protected function login($additionalParams = [])
	{
		$params = array_merge([
			'email' => $this->email,
			'password' => $this->password
		], $additionalParams);

		$r = $this->httpClient->send($this->getNewGuzzleRequest(self::LOGIN_URL, "POST", $params));

		if ($r->getStatusCode() != 200)
		{
			return false;
		}

		return $r->getBody()->getContents();
	}

	protected function createHttpClient()
	{
		$this->httpClient = new Client();
	}

	protected function createCookieJar(array $cookies = [])
	{
		$this->cookieJar = new CookieJar(true, $cookies);
	}

	protected function getNewGuzzleRequest($url, $method = "GET", array $params = [], $options = [])
	{
		$options = array_merge([
			'cookies' => $this->cookieJar,
			'body' => $params
		], $options);

		return $this->httpClient->createRequest($method, $url, $options);
	}

	/**
	 * Save upgrade data from this object to the session.
	 */
	protected function save()
	{
		\XF::app()->session()->set('xenforoUpgradeService', [
			'cookieJar' => $this->getCookies(),
			'email' => $this->email,
			'password' => $this->password,
			'availableProducts' => $this->availableProducts,
			'availableVersions' => $this->availableVersions,
			'selectedLicense' => $this->selectedLicense,
			'selectedProduct' => $this->selectedProduct,
			'selectedVersion' => $this->selectedVersion
		]);
	}
}