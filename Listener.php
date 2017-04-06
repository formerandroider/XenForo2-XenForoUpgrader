<?php

namespace LiamW\XenForoUpgrade;

class Listener
{
	public static function appSetup(\XF\App $app)
	{
		self::addAutoloadStuff();
	}

	public static function addAutoloadStuff()
	{
		\XF::$autoLoader->add('FtpClient', 'src/addons/LiamW/XenForoUpgrade/lib/FtpClient/src');
	}
}