<?php

namespace CubeTools\CubeCommonDevelop\Test\WebTest;

use Symfony\Component\Yaml\Yaml;

/**
 * Smoke Test for pages not tested elsewhere.
 *
 * uses some files:
 *   PageLoadingTest_routes.yml, generated when running the test with environment variable PageLoading_Load=1
 *   PageLoadingTest_special.yml, settings for some urls
 * this files are next to the class or in tests/
 *
 * @group pageloading // not inherited, must be set in subclass
 */
class SmoketestPageLoadingBase extends WebTestCube
{
    /**
     * @dataProvider listUrls
     */
    public function testSimplePageLoading($method, $path, $info)
    {
        $aw = $this->loadPage($method, $path, $info);
        $this->assertEquals(200, $aw['code'], $aw['msg']);
    }

    protected function loadPage($method, $path, $info)
    {
        if ($method == 'POST_ANY') {
            $method = 'POST';
        } elseif (!in_array($method, array('GET', 'DELETE', 'PATCH', 'POST', 'PUT'))) {
            $this->markTestIncomplete(sprintf('method %s not yet supported', $method));

            return;
        }

        $client = $this->getClient(true);
        $crawler = $client->request($method, $path);
        $code = $client->getResponse()->getStatusCode();
        $msg = 'WRONG status code';
        switch ($code) {
            case 200:
                if (isset($info->redirect)) {
                    $this->AssertTrue(false, 'expected redirect to '.$info->redirect);
                }
                break;
            case 404:
            case 500:
                $msg .= ': '.$this->getPageLoadingFailure($crawler, $this->getName());
                if (isset($info->lazy) && (false !== strpos($msg, 'local problem') || false !== strpos($msg, 'Undefined ') || false !== strpos($msg, 'Variable "'))) {
                    unset($info->lazy); // do not ignore local problems and undefined variable/index
                }
                break;
            default:
                if ($client->getResponse()->isRedirect()) {
                    $redirect = $client->getResponse()->getTargetUrl();
                    if (isset($info->redirect)) {
                        if ($client->getResponse()->isRedirect($info->redirect)) {
                            $this->assertTrue(true);
                        } else {
                            $this->assertTrue(false, 'redirect to '.$redirect.' instead of '.$info->redirect);
                        }
                        $code = 200; // set to pass
                    } else {
                        $msg = static::msgUnexpectedRedirect($client);
                    }
                    if ('POST' !== $method && 'DELETE' !== $method) {
                        // simply fail
                    } elseif (false !== strpos($path, $redirect)) {
                        // redirect to a parent URL after POST/DELETE
                        $this->markTestSkipped("maybe $msg");
                    } else {
                        $this->markTestIncomplete($msg);
                    }
                }
        }
        if (200 != $code && isset($info->lazy)) {
            //TODO allow matching against codes or msgs
            $this->markTestIncomplete('failed lazy: '.$msg);
        }

        return array('code' => $code, 'msg' => $msg);
    }

    /**
     * @dataProvider listUrls
     */
    public function testSimplePageLoadingWithParameterUrl($method, $url, $info)
    {
        if (null === $method && null === $url && null === $info) {
            $this->markTestSkipped('OK, no loading with parameterUrl');
        }
        $url = $this->replaceUrlParameter($url, $info, $method);
        $aw = $this->loadPage($method, $url, $info);
        if ($aw['code'] == 404 && (strpos($aw['msg'], 'entity') || strpos($aw['msg'], ' not found')) ||
            $aw['code'] == 500 && strpos($aw['msg'], 'The file "') && strpos($aw['msg'], '" does not exist (500 Internal Server Error)')
        ) {
            // id 1 does probably not exist locally, mark as skipped
            $this->markTestSkipped('missing resource locally - '.$aw['msg']);
        } elseif ($aw['code'] == 302 && strpos($aw['msg'], 'flashbag: {"general-notice":["You are not allowed ')) {
            // no rights for this
            $this->markTestSkipped('no access right on local resource - '.$aw['msg']);
        }
        $this->assertEquals(200, $aw['code'], $aw['msg']);
    }

    /**
     * @dataProvider listUrls
     */
    public function testKnownProblem($method, $url, $info)
    {
        if (null === $method && null === $url && null === $info) {
            $this->markTestSkipped('OK, no known problem');
        }
        $problem = $info->knownProblem['problemName'];
        $url = $this->replaceUrlParameter($url, $info, $method);
        $aw = $this->loadPage($method, $url, $info);
        if ($aw['code'] != 200) {
            if (!preg_match('~'.$info->knownProblem['msgMatch'].'~', $aw['msg'])) {
                // is not known problem
                $this->AssertEquals(200, $aw['code'], $aw['msg']);
            }
            // problem known and matches description
            $this->markTestSkipped('known problem ('.$problem.') - '.$aw['msg']);
        } else {
            $this->markTestIncomplete('PASSED, but marked as known problem ('.$problem.')');
        }
    }

    /**
     * @dataProvider listUrls
     */
    public function testIgnore($method, $url, $info)
    {
        if (null === $method && null === $url && null === $info) {
            $this->markTestSkipped('OK, nothing ignored');
        }
        $this->markTestIncomplete($info->ignore);
    }

    /**
     * Generates the route data similar to 'app/console debug:route' but only urls interested in.
     *
     * @return array with route information
     */
    public static function &generateUrlData()
    {
        if (null === self::$kernel || null === self::$kernel->getContainer()) {
            // kernel not booted (properly)
            self::bootKernel();
        }
        $routes = self::$kernel->getContainer()->get('router')->getRouteCollection();
        $result = array();
        foreach ($routes as $name => $route) {
            // only use AppBundle urls, the rest is not interesting and too much data
            $controller = $route->getDefault('_controller');
            if (strtok($controller, ':\\.') == 'AppBundle') {
                $infos = array('path' => $route->getPath(),
                    'methods' => $route->getMethods(),
                    'defaults' => $route->getDefaults(),
                );
                $result[$name] = $infos;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * Loads the url data and its settings from disc (or generates the route data on demand).
     *
     * @return array with route informaton
     */
    public static function loadUrlData()
    {
        if (self::class === static::class) {
            $rPath = '../../../../../../tests/PageLoadingTest_routes.yml';
        } else {
            $clsRefl = new \ReflectionClass(static::class);
            $rPath = str_replace('.php', '_routes.yml', $clsRefl->getFileName());
        }
        $curUrls = static::generateUrlData();
        if (getenv('PageLoading_Load')) {
            $urls = $curUrls;
            file_put_contents($rPath, Yaml::dump($urls));
            print " # routes file regenerated\n";
        } else {
            $urls = Yaml::parse(file_get_contents($rPath));
            $newFound = false;
            foreach ($curUrls as $name => $data) {
                if (!isset($urls[$name]) || $urls[$name] != $data) {
                    $urls[$name.' __new__'] = $data;
                    $newFound = true;
                }
            }
            if ($newFound) {
                echo "  ** run `PageLoading_Load=1 phpunit --filter=matchNoTest` to update $rPath\n\n";
            }
        }
        $sPath = str_replace('_routes.yml', '_special.yml', $rPath);
        if (file_exists($sPath)) {
            $specials = Yaml::parse(file_get_contents($sPath));
        } else {
            $specials = array();
        }
        foreach ($urls as $name => &$data) {
            $special = isset($specials['tests'][$name]) ? $specials['tests'][$name] : array();
            $type = '';
            if (empty($special)) {
                // nothing, quick path
            } elseif (isset($special['tested'])) {
                $type = '_tested';
            } elseif (isset($special['ignore'])) {
                $type = 'testIgnore';
            } elseif (isset($special['knownProblem'])) {
                $problemName = $special['knownProblem'];
                if (isset($specials['knownProblem'][$problemName])) {
                    $special['knownProblem'] = $specials['knownProblem'][$problemName];
                    $special['knownProblem']['problemName'] = $problemName;
                } elseif (false !== strpos($problemName, ' ')) {
                    $special['knownProblem'] = array('problemName' => 'simpleKnown', 'msgMatch' => $problemName);
                } else {
                    throw new \Exception($problemName.' is not defined in knownProblem');
                }
                $type = 'testKnownProblem';
            }
            if ($type != '') {
                // type already set
            } elseif (strpos($data['path'], '{')) {
                $type = 'testSimplePageLoadingWithParameterUrl';
            } else {
                $type = 'testSimplePageLoading';
            }
            $data['testType'] = $type;
            $data['testSpecial'] = $special;
        }

        return $urls;
    }

    public static $urlData = null;

    /**
     * @param string $testMethodName name of test method the dataprovider is called for
     */
    public static function listUrls($testMethodName)
    {
        if (static::$urlData === null) {
            static::$urlData = static::loadUrlData();
        }
        $i = 0;
        foreach (static::$urlData as $name => $data) {
            if ($data['testType'] == $testMethodName) {
                $info = $data['testSpecial'];
                $info['controller'] = $data['defaults']['_controller'];
                $methods = $data['methods'];
                if (empty($methods)) {
                    $methods = array('GET', 'POST_ANY');
                    $c = 2;
                } else {
                    $c = count($methods);
                }
                foreach ($methods as $method) {
                    if (1 === $c || 'GET' === $method) {
                        $mName = $name;
                    } else {
                        $mName = "${name}_${method}";
                    }
                    yield $mName => array(
                        'method' => $method,
                        'path'   => $data['path'],
                        'info'   => (object) $info,
                    );
                }
                ++$i;
            }
        }
        if (0 === $i && 'testSimplePageLoading' !== $testMethodName) {
            // would report an error when no tests returned
            yield 'skip' => array(null, null, null); // static::markTestSkipped does not yet work with phpunit 3.7.28
        }
    }

    protected function getDataSetAsString($includeData = true)
    {
        $buffer = parent::getDataSetAsString($includeData);
        if ($includeData && $buffer) {
            $p = strpos($buffer, ', stdClass');
            if ($p !== false) {
                $buffer = substr_replace($buffer, ', ..)', $p);
            }
        }

        return $buffer;
    }

    private static function replaceUrlParameter($url, $info, $method)
    {
        $nr = 1;
        if ('DELETE' === $method) {
            $nr = 99999; // probably non existing
        }
        $replace = array('{id}' => $nr);
        if (isset($info->urlParameters)) {
            $replace = array_merge($replace, $info->urlParameters);
        }
        $url = strtr($url, $replace);
        if (strpos($url, '{')) {
            static::markTestIncomplete('parameter in url only supported partially');
        }

        return $url;
    }
}
