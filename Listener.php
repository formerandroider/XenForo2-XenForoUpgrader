<?php

namespace LiamW\XenForoUpgrade;

use Composer\Autoload\ClassLoader;

class Listener
{
	public static function appSetup(\XF\App $app)
	{
		self::addAutoloadStuff();
	}

	public static function addAutoloadStuff()
	{
		$autoloadMethods = spl_autoload_functions();

		if (isset($autoloadMethods[0]) && is_array($autoloadMethods[0]))
		{
			$classLoaderObj = $autoloadMethods[0][0];

			if ($classLoaderObj instanceof ClassLoader)
			{
				$classLoaderObj->add('FtpClient', 'src/addons/LiamW/XenForoUpgrade/lib/FtpClient/src');
			}
		}
	}
}