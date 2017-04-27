#!/usr/bin/env php
<?php

namespace CubeTools\CubeCommonDevelop;

use Symfony\Component\Console\Application;
use CubeTools\CubeCommonDevelop\CodeStyle;

function addCcdCommands($application)
{
    $application->add(new Command\CheckXliffFiles());
    $application->add(new Command\CheckHtmlTwig());
}

function initCommands()
{
    $bootstrap = null;
    $appDir = __DIR__.'/../../../../../';
    $bootstrapDirs = array('./var/', './app/', $appDir.'var/', $appDir.'app/');
    $bootstrapDirs[] = $bootstrapDirs[0];
    foreach ($bootstrapDirs as $bootstrapDir) {
        $bootstrap = $bootstrapDir.'bootstrap.php.cache';
        if (file_exists($bootstrap)) {
            break;
        }
    }
    require_once $bootstrap;

    $application = new Application();
    addCcdCommands($application);

    return $application->run();
}

$_mainFile = !\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
if ($_mainFile) {
    $r = initCommands();
    echo $r;
}
