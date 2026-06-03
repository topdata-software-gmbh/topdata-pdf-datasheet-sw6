<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:pdf-datasheet:cache-clear',
    description: 'Clears the generated PDF datasheets disk cache'
)]
class CacheClearCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly string $cacheDir
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cacheSubdir = $this->cacheDir . '/topdata_pdf_datasheet';

        if (!is_dir($cacheSubdir)) {
            CliLogger::info('Cache directory does not exist or is already empty.');
            return Command::SUCCESS;
        }

        $files = glob($cacheSubdir . '/*.pdf');
        if (empty($files)) {
            CliLogger::info('No cached PDF files found.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        CliLogger::success(sprintf('Cleared %d cached PDF file(s).', $count));

        return Command::SUCCESS;
    }
}
