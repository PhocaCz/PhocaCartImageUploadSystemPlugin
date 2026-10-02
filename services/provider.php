<?php

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Phocacartimageupload\Extension\Phocacartimageupload;

if (!class_exists(Phocacartimageupload::class)) {
    \JLoader::registerNamespace('Joomla\\Plugin\\System\\Phocacartimage', JPATH_PLUGINS . '/system/phocacartimage/src');

    if (!class_exists(Phocacartimageupload::class)) {
        require_once __DIR__ . '/../src/Extension/Phocacartimageupload.php';
    }
}

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container) {
            $plugin = new Phocacartimageupload(
                $container->get(DispatcherInterface::class),
                (array) PluginHelper::getPlugin('system', 'phocacartimageupload')
            );
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
