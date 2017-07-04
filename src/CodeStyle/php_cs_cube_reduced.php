<?php
    /*
     * configuration for php-cs-fixer 2.x (reduced cubetools standard)
     *
     * use like this in .php_cs.dist:
     * return require __DIR__.'vendor/cubetools/cube-common-develop/src/CodeStyle/php_cs_cube_reduced.php';
     *
     * or "php-cs-fixer fix --config=path/to/php_cs_cube.php ..."
     */

namespace CubeTools\CubeCommonDevelop\CodeStyle;

$finder = require __DIR__.'/php_cs_cube.php';

$rules = array_merge(array('@PSR2' => true), $finder->getRules());
unset($rules['@Symfony']);
$finder->setRules($rules);

return $finder;
