<?php

namespace CubeTools\CubeCommonDevelop\CodeStyle;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class HtmlTwig
{
    public static function checkHtmlInTwig($fileName)
    {
        $internalErrors  = libxml_use_internal_errors(true);
        $disableEntities = libxml_disable_entity_loader(true);
        if ($internalErrors) {
            libxml_clear_errors();
        }
        $errors = array();
        try {
            $content = @file_get_contents($fileName);
            if (!$content) {
                $e         = new Helpers\HtmlInTwigError(new \LibXMLError());
                $e->column = null;
                $e->code   = 0;
                $e->line   = 0;
                if (!is_file($fileName)) {
                    $e->level   = LIBXML_ERR_WARNING;
                    $e->message = "not a file\n";

                    return array($e);
                } elseif (filesize($fileName)) {
                    $e->level   = LIBXML_ERR_ERROR;
                    $e->message = "could not read file\n";

                    return array($e);
                }
                $content = ' '; // file is empty
            }
            $pos = 0;
            while (($scrSt = strpos($content, '<script', $pos)) !== false) {
                $scrEn = strpos($content, '</script>', $scrSt);
                $scrSt += 8;
                $scrEn -= 8 + 1;
                $partC = substr($content, $scrSt, $scrEn - $scrSt);
                if (false) { // TODO
                    $lineOffset = '>'; // TODO calculate line offset
                    self::checkHtmlPartially($partC, $lineOffset);
                }
                $partC = strtr($partC, '<', '#'); // replace < by # in tag
                $content = substr_replace($content, $partC, $scrSt, $scrSt - $scrEn);
                $pos     = $scrEn;
            }
            $errors = array_merge($errors, self::checkHtmlPartially($content, 0));
        } finally {
            libxml_use_internal_errors($internalErrors);
            libxml_disable_entity_loader($disableEntities);
        }

        return $errors;
    }

    protected static function checkHtmlPartially($html, $lineOffset)
    {
        $d                  = new \DOMDocument(1.0);
        $d->validateOnParse = true;
        $d->loadHTML($html);
        $d         = null;
        $xmlErrors = libxml_get_errors();
        $errors    = array();
        $htmlLines = null;
        foreach ($xmlErrors as $error) {
            if (self::ignoreError($error)) {
                continue;
            }
            $error = new Helpers\HtmlInTwigError($error);
            if (null === $htmlLines) {
                $htmlLines = explode("\n", $html);
            }
            $error->ctxLine = $htmlLines[$error->line - 1];
            if (is_string($lineOffset)) {
                $error->line = $lineOffset.$error - line;
            } else {
                $error->line += $lineOffset;
            }
            $errors[] = $error;
        }
        libxml_clear_errors();

        return $errors;
    }

    protected static function ignoreError(\LibXMLError $error)
    {
        if ($error->level == LIBXML_ERR_ERROR) {
            return $error->code == 801 && strpos($error->message, 'nav') !== false; // tag nav unknown
        }

        return false;
    }
}
