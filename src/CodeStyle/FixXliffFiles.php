<?php

namespace CubeTools\CubeCommonDevelop\CodeStyle;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class FixXliffFiles extends Command
{
    public function fixXliffFile($fileName, $doFix = false)
    {
        if (!file_exists($fileName)) {
            return array('file not found');
        }
        $content = \file_get_contents($fileName);
        $crawler = new Crawler();
        $crawler->addXMLContent($content);
        $fixed = array();
        $runs = $crawler->filter('body trans-unit')->each(function ($unit) use (&$fixed) {
            $this->checkUnit($unit, $fixed);
        });
        if ($fixed) {
            $xmlDoc = $crawler->getNode(0)->ownerDocument;
            $xmlDoc->encoding = 'utf-8';
            $xmlContent = $xmlDoc->saveXML();
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

    protected function configure()
    {
        $this
            ->setName('lint:xliff:cubestyle')
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'files to check')
            ->addOption('fix', 'f', InputOption::VALUE_NONE, 'write file directly')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $files = $input->getArgument('files');
        $doFix = $input->getOption('fix');
        $nErrors = 0;
        $eFiles = 0;
        $cFiles = 0;
        foreach ($files as $file) {
            ++$cFiles;
            $output->write($file);
            $errors = $this->fixXliffFile($file, $doFix);
            if ($errors) {
                $n = count($errors);
                $output->writeln(sprintf(' <error>%d ERRORS</>', $n));
                $nErrors += $n;
                foreach ($errors as $error) {
                    $msg = sprintf(
                        " * <comment>%s</>\n",
                        $error
                    );
                    $output->write($msg);
                }
                ++$eFiles;
            } else {
                $output->writeln(' <info>[OK]</>');
            }
        }
        if ($nErrors) {
            $msg = '<comment>%s</>: <error>%d Errors</> in <error>%d</> files (checked %d of %d)';
            $output->writeln(\sprintf($msg, $this->getName(), $nErrors, $eFiles, $cFiles, \count($files)));
        } else {
            $output->writeln(\sprintf('%s: <info>[OK] checked %d files</>', $this->getName(), \count($files)));
        }

        return $nErrors;
    }

    private function checkUnit($unit, array &$fixed)
    {
        $id = $unit->attr('id');
        $sourceTxt = $unit->filter('source')->text();
        if (false === strpos($sourceTxt, $id) && false === strpos(strtolower($sourceTxt), $id)) {
            $spacePos = strpos($sourceTxt, ' %');
            if (false !== $spacePos) {
                $nId = substr($sourceTxt, 0, $spacePos);
            } else {
                $nId = $sourceTxt;
            }
            $unit->getNode(0)->setAttribute('id', $nId);
            $fixed[] = 'id of "'.substr(strtr($sourceTxt, array("\n" => "\\n")), 0, 128).'"';
        }
    }
}
