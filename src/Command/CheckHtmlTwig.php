<?php

namespace CubeTools\CubeCommonDevelop\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use CubeTools\CubeCommonDevelop\CodeStyle\HtmlTwig;

class CheckHtmlTwig extends Command
{
    protected function configure()
    {
        $this
            ->setName('lint:html.twig')
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'files to check')
            ->addOption('errorFilesLimit', 'e', InputOption::VALUE_REQUIRED, 'stop after this many error files', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $files     = $input->getArgument('files');
        $stopAfter = $input->getOption('errorFilesLimit');
        if ((string) (int) $stopAfter == $stopAfter) {
            $stopAfter = (int) $stopAfter;
        } else {
            throw new InvalidArgumentException('--errorFiles= must be int');
        }
        $nErrors = 0;
        $eFiles  = 0;
        $cFiles  = 0;
        $fmt     = null;
        foreach ($files as $file) {
            ++$cFiles;
            $output->write($file);
            $errors = HtmlTwig::checkHtmlInTwig($file);
            if ($errors) {
                $n = count($errors);
                $output->writeln(sprintf(' <error>%d ERRORS</>', $n));
                $nErrors += $n;
                foreach ($errors as $error) {
                    $msg = sprintf(
                        ' * <comment>%s%d</> Line %s (c%s) <comment>%s</>',
                        $error->getErrLevel(),
                        $error->code,
                        $error->line,
                        $error->column,
                        $error->message
                    );
                    $output->write($msg);
                    if (null !== $error->column) {
                        if (null === $fmt) {
                            $fmt = $output->getFormatter();
                        }
                        $msg = sprintf(
                            '    <options=underscore>%s<error>%s</error>%s</>',
                            $fmt::escape($error->getContextA()),
                            $fmt::escape($error->getContextE()),
                            $fmt::escape($error->getContextZ())
                        );
                        $output->writeln($msg);
                    }
                }
                ++$eFiles;
                if (0 !== $stopAfter && $eFiles >= $stopAfter) {
                    break;
                }
            } else {
                $output->writeln(' <info>[OK]</>');
            }
        }
        if ($nErrors) {
            $msg = '<comment>lint:html.twig</>: <error>%d Errors</> in <error>%d</> files (checked %d of %d)';
            $output->writeln(sprintf($msg, $nErrors, $eFiles, $cFiles, count($files)));
        } else {
            $output->writeln(sprintf('lint:html.twig: <info>[OK] checked %d files</>', count($files)));
        }

        return $nErrors;
    }
}
