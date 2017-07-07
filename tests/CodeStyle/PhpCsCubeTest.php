<?php

namespace Tests\CubeTools\CubeCommonDevelop\CodeStyle;

class PhpCsCubeTest extends \PHPUnit\Framework\TestCase
{
    public function testCubeStyle()
    {
        $config = require __DIR__.'/../../src/CodeStyle/php_cs_cube.php';
        $this->assertInternalType('object', $config->getFinder());
        $rules = $config->getRules();
        $this->assertTrue($rules['@Symfony']);
    }

    public function testCubeStyleReduced()
    {
        $config = require __DIR__.'/../../src/CodeStyle/php_cs_cube_reduced.php';
        $this->assertInternalType('object', $config->getFinder());
        $rules = $config->getRules();
        $this->assertTrue($rules['@PSR2']);
        $this->assertArrayNotHasKey('@Symfony', $rules);
    }
}
