<?php

namespace CubeTools\CubeCommonDevelop\CodeStyle;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class XliffFiles
{
    public static function fixXliffFile($fileName, $doFix = false, $reindent = false)
    {
        if (!file_exists($fileName)) {
            return array('file not found');
        }
        $content = \file_get_contents($fileName);
        $crawler = new Crawler();
        $crawler->addXMLContent($content);
        $fixed = array();
        $runs = $crawler->filter('body trans-unit')->each(function ($unit) use (&$fixed) {
            self::checkUnit($unit, $fixed);
        });
        if ($fixed) {
            $xmlDoc = $crawler->getNode(0)->ownerDocument;
            $xmlDoc->encoding = 'utf-8';
            if ($reindent) {
                $xmlDoc->preserveWhiteSpace = false;
                $xmlDoc->formatOutput = true;
                $xmlDoc->loadXML($xmlDoc->saveXML()); // must reload because format is applied on loading only
                $xmlContent = \preg_replace('/^( +)\</m', '$1$1<', $xmlDoc->saveXML()); // change indentation from 2 to 4
            } else {
                $xmlContent = $xmlDoc->saveXML();
            }
            $xmlContent = \str_replace(' ns="', ' xmlns="', \substr($xmlContent, 0, 128)).\substr($xmlContent, 128);
            // str_replace because xmlns= is changed to ns=. (by Crawler.)
            $nBytes = \file_put_contents($fileName.'#', $xmlContent);
            if ($doFix && $nBytes) {
                rename($fileName, $fileName.'~');
                rename($fileName.'#', $fileName);
            } elseif ($doFix) {
                $fixed[] = 'FAILED to write file';
            }
        } elseif (!$runs) {
            $err = libxml_get_last_error();
            if ($err) {
                $fixed[] = 'ERROR in xml: '.$err->message;
            } else {
                $fixed[] = 'WARNING, no elements found, maybe xml not well-formatted';
            }
        }

        return $fixed;
    }

    private static function checkUnit($unit, array &$fixed)
    {
        $id = $unit->attr('id');
        $sourceTxt = $unit->filter('source')->text();
        if (self::invalidId($id, $sourceTxt)) {
            $spacePos = strpos($sourceTxt, ' %');
            if (false !== $spacePos) {
                $nId = substr($sourceTxt, 0, $spacePos);
            } elseif (false !== strpos($sourceTxt, ' ') && strlen($sourceTxt) > 64) {
                $nId = substr($sourceTxt, 0, 64 - 8 - 1).'_'.substr(md5($sourceTxt), 3, 8);
            } else {
                $nId = $sourceTxt;
            }
            if ($id !== $nId) {
                $node = $unit->getNode(0);
                $node->setAttribute('id', $nId);
                $node->removeAttribute('resname'); // unwanted
                $fixed[] = 'id of "'.substr(strtr($sourceTxt, array("\n" => "\\n")), 0, 128).'"';
            }
        }
        if (false !== strpos($sourceTxt, ' %') && false === strpos($unit->filter('target')->text(), ' %')) {
            $fixed[] = 'TODO include parameters in source "'.strtr($sourceTxt, array("\n", "\\n")).'" (from target )';
        }
    }

    private static function invalidId($id, $sourceTxt)
    {
        return !$id || false !== strpos($id, ' %') ||
            (false === strpos($sourceTxt, $id) && false === strpos(strtolower($sourceTxt), $id));
    }
}
