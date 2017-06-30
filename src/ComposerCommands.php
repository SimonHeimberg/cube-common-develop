<?php

namespace CubeTools\CubeCommonDevelop;

use Incenteev\ParameterHandler\ScriptHandler;
use Composer\Script\Event;

class ComposerCommands
{
    /**
     * Composer command to ask the user for unset test parameters.
     *
     * The parameter file name is taken from the incenteev-parameters. The
     * original name (often parameters.yml) is modified to include _test before
     * the file ending (often resulting in parameters_test.yml).
     */
    public static function buildTestParameters(Event $event)
    {
        if (count($event->getArguments()) === 1) {
            $postfix = $event->getArguments()[0];
        } else {
            $postfix = '_test';
        }
        $package = $event->getComposer()->getPackage();
        $extras = $package->getExtra();
        $config = $extras['incenteev-parameters'];
        if (array_keys($config)[0] === 0) {
            // it is an array of configs
            $config = $config[0];
        }
        $cfgFile = $config['file'];
        $config['file'] = str_replace('.yml', $postfix.'.yml', $config['file']);
        $config['keep-outdated'] = true;
        $extras['incenteev-parameters'] = array($config);
        $package->setExtra($extras);

        $ret = ScriptHandler::buildParameters($event);

        if (is_file($cfgFile)) {
            touch($cfgFile); // to force the container to rebuild
        }

        return $ret;
    }
}
