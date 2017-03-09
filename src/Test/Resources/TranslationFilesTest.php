<?php

namespace CubeTools\CubeCommonDevelop\Tests\Resources;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/*
* test if translation files are loadable
*
*/

class TranslationFilesTest extends KernelTestCase
{
    /**
     * @dataProvider listLanguages
     */
    public function testLanguageFile($langName)
    {
        static::bootKernel();
        $tr = static::$kernel->getContainer()->get('translator');
        $cat = $tr->getCatalogue($langName);
        $texts = array('label.addresslist.user.phone'); # TODO read from settings? xlf file? $cat->getResources() $path = xxx->getResource ...  
        foreach ($texts as $text) {
            $r = $cat->defines($text);
            $msg = 'translation problem';
            if (!$r) {
                $msg = sprintf('translation of "%s" missing', $text);
                if ($langName == 'it') { # TODO read from ...  
                    $this->markTestSkipped($msg);
                }
            }
            $this->AssertTrue($r, $msg);
        }
    }


    public static function listLanguages()
    {
        # TODO automatic check if all are listed (from {app,src,...}/Resources/translations/{messages,...}.*.x*)  
        $languages = array('en', 'de', 'fr', 'it');
        foreach ($languages as $lang) {
            yield $lang => array($lang);
        }
    }
}
