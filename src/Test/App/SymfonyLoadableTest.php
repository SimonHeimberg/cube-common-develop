<?php

namespace CubeTools\CubeCommonDevelop\Test\App;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Basic test to check if symfony binaries are loadable.
 *
 * Extend this class in a test of the project, or create a test file containing "new SymfonyLoadableTest();"
 *
 * Tests if web/app.php, web/app_dev.php and xxx/console are runnable.
 * Usable when modifying autoloading or console location, or ...
 */
class SymfonyLoadableTest extends \PHPUnit_Framework_TestCase
{
    /**
     *
     * @var string php executable path
     */
    private static $php;

    public static function setUpBeforeClass()
    {
        $executableFinder = new PhpExecutableFinder();
        self::$php = $executableFinder->find();
    }

    /**
     * @dataProvider getAppNames
     */
    public function testAppRunnable($appPath)
    {
        $p = new Process(self::$php.' '.$appPath, null, null, 5);
        $p->mustRun();
        $this->assertEquals('', $p->getErrorOutput(), 'no error output');
        $this->assertNotEmpty($p->getOutput(), 'some output');
    }

    public static function getAppNames()
    {
        yield 'prod' => array('web/app.php');

        foreach (glob('web/app_*.php') as $appPath) {
            yield basename($appPath) => array($appPath);
        }
    }

    public function testConsoleRunnable()
    {
        $console = 'bin/console';
        if (!file_exists($console)) {
            $console = 'app/console';
        }
        $p = new Process(self::$php.' '.$console.' -V', null, null, 5);
        $p->mustRun();
        $this->assertEquals('', $p->getErrorOutput(), 'no error output');
        $this->assertContains('ersion', $p->getOutput(), 'some output');
        if ('bin/' === substr($console, 0, 4)) {
            $this->assertTrue(is_dir('var'), 'var/ exists if console in bin/');
        }
    }
}
