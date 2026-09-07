<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ymmudesocial
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container->set(
            'plg_system_ymmudesocial',
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);

                return new \PlgSystemYmmudesocial(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('system', 'ymmudesocial')
                );
            }
        );
    }
};
