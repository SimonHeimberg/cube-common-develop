<?php

namespace CubeTools\CubeCommonDevelop\Test\WebTest;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Routing\Route;

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
class SmoketestPageLoadingBase extends WebTestBase
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
        if (200 !== $code && isset($info->passOrAnyOf)) {
            $matched = $this->matchAnyOf($code, $msg, $info->passOrAnyOf);
            if (null === $matched) {
                // no match, will fail
            } elseif (isset($matched['pass']) && $matched['pass']) {
                $code = 200; // set to pass
            } elseif (isset($matched['msg']) && '.' === $matched['msg']) {
                $this->markTestSkipped('failed ('.$matched['name'].'): '.$msg);
            } else {
                $this->markTestIncomplete('failed ('.$matched['name'].'): '.$msg);
            }
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
        $url = $this->replaceUrlParameter($url, $info, $method);
        $aw = $this->loadPage($method, $url, $info);
        if ($aw['code'] != 200) {
            $matched = $this->matchAnyOf($aw['code'], $aw['msg'], $info->knownProblem);
            if (null === $matched) {
                // no match, fail
                $this->AssertEquals(200, $aw['code'], $aw['msg']);
            } else {
                // problem known and matches description
                $this->markTestSkipped('known problem ('.$matched['name'].'): '.$aw['msg']);
            }
        } else {
            $this->markTestIncomplete('PASSED, but marked as known problem');
        }
    }

    /**
     * @dataProvider listUrls
     */
    public function testExpectError($method, $url, $info)
    {
        if (null === $method && null === $url && null === $info) {
            $this->markTestSkipped('OK, no expected failure');
        }
        $url = $this->replaceUrlParameter($url, $info, $method);
        $aw = $this->loadPage($method, $url, $info);
        $matched = $this->matchAnyOf($aw['code'], $aw['msg'], $info->expectError);
        if (null !== $matched) {
            // matches
            if (isset($matched['pass']) && $matched['pass']) {
                $this->assertTrue(true);
            } else {
                $this->markTestIncomplete('failed ('.$matched['name'].'): '.$aw['msg']);
            }
        } elseif (200 === $aw['code']) {
            $this->fail('should have an error, but passed');
        } else {
            $this->fail('failed with wrong error (with '.$aw['code'].' - '.$aw['msg'].')');
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
            // only use interesing urls, the rest is too much uninteresing data
            if (static::interestedInRoute($route)) {
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
     * Returns true when we are interested in this route (when route from AppBundle).
     *
     * Can be overwritten in a subclass to adapt to what is interesting in a project.
     *
     * @param Route $route
     *
     * @return bool true when interested in this route
     */
    public static function interestedInRoute(Route $route)
    {
        $controllerName = $route->getDefault('_controller');
        $topName = strtok($controllerName, ':\\.');

        return 'AppBundle' === $topName;
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
            if (file_put_contents($rPath, Yaml::dump($curUrls))) {
                $urls = $curUrls;
                print " # routes file regenerated\n";
            } else {
                print " # FAILED generating routes file\n";
            }
        } else {
            $newFound = false;
            if (file_exists($rPath)) {
                $urls = Yaml::parse(file_get_contents($rPath));
            } else {
                $urls = array();
                $todoUrl = array('defaults' => array('_controller' => ''), 'methods' => array('GET'));
                $todoUrl['path'] = 'TODO: update _routes.yml, see hint at top.';
                $curUrls = array_merge(array('TODO' => $todoUrl), $curUrls);
            }
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
                $type = 'testKnownProblem';
            } elseif (isset($special['expectError'])) {
                $type = 'testExpectError';
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

        return array('urls' => $urls, 'specials' => $specials);
    }

    protected static $urlData = null;
    protected static $specials = null;

    /**
     * @param string $testMethodName name of test method the dataprovider is called for
     */
    public static function listUrls($testMethodName)
    {
        if (static::$urlData === null) {
            $loaded = static::loadUrlData();
            static::$urlData = $loaded['urls'];
            static::$specials = $loaded['specials'];
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

    /**
     * To overwrite in subclass when unknown parameters should be skipped.
     *
     * TODO remove if not used anymore.
     *
     * @return boolean false
     */
    protected function skipUnknownRouteParameters()
    {
        return false;
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
        if ($this->skipUnknownRouteParameters() && strpos($url, '{')) {
            static::markTestIncomplete('skipped non-id parameter in url');
        }

        return $url;
    }

    /**
     * Checks if error ($msg and $code) match any of the given variants.
     *
     * @param int $code    error code
     * @param string $msg  error message
     * @param array|string $anyOf '@...' or array of ('@...' or ['msg' => string, 'code' => int, ...], msg or code must be present)
     *
     * @return null|array Matching element of $anyOf (with ['name'] set to key), null else.
     */
    private static function matchAnyOf($code, $msg, $anyOf)
    {
        if (is_string($anyOf) && $anyOf && '@' === $anyOf[0]) {
            $anyOf = self::getFromConfigSpecials('anyMatchGroup', substr($anyOf, 1));
            if (null === $anyOf) {
                return null;
            }
        } elseif (!is_array($anyOf) && !$anyOf instanceof \Traversable) {
            $msg = "\$anyOf must be '@xxx' or array, but it is ".gettype($anyOf).' ('.print_r($anyOf, true).')';
            trigger_error($msg, E_USER_WARNING);

            return null;
        }
        foreach ($anyOf as $name => $any) {
            if (is_string($any) && $any && '@' === $any[0]) {
                $name .= ' '.$any;
                $any = self::getFromConfigSpecials('anyMatch', substr($any, 1));
                if (null === $any) {
                    return null;
                }
            }
            if (is_string($any)) {
                $match = $any;
                $any = array();
            } elseif (isset($any['code']) && $code != $any['code']) {
                continue; // code did not match
            } elseif (!isset($any['msg']) && isset($any['code'])) {
                $match = ''; // will match
            } elseif (!isset($any['msg'])) {
                trigger_error("'msg' or 'code' must be set, missing in ".$name, E_USER_WARNING);
                continue;
            } else {
                $match = $any['msg'];
            }
            if (preg_match('~'.$match.'~', $msg)) {
                $any['name'] = $name;

                return $any;
            }
        }

        return null;
    }

    /**
     * Read form special config, warn if not existing (and return null).
     *
     * @param string $group 1st config level below specials
     * @param string $key   2nd config level below specials
     *
     * @return any
     */
    private static function getFromConfigSpecials($group, $key)
    {
        if (empty(self::$specials[$group][$key])) {
            $msg = "$key must be in specials.$group on ";
            trigger_error($msg, E_USER_WARNING);

            return none;
        }

        return self::$specials[$group][$key];
    }
}
