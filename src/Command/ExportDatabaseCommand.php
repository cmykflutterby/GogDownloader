<?php

namespace App\Command;

use App\DTO\GameDetail;
use App\DTO\OwnedItemInfo;
use App\DTO\SearchFilter;
use App\Enum\Language;
use App\Enum\MediaType;
use App\Enum\OperatingSystem;
use App\Exception\TooManyRetriesException;
use App\Service\OwnedItemsManager;
use App\Service\DownloadManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use League\Csv\ByteSequence;
use League\Csv\Writer;

#[AsCommand('export-database')]
final class ExportDatabaseCommand extends Command
{
    public function __construct(
        private readonly OwnedItemsManager $ownedItemsManager,
        private readonly DownloadManager $downloadManager,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Exports the games/files database to Excel-compatible CSV')
            ->addOption(
                'filename',
                null,
                InputOption::VALUE_REQUIRED,
                'Set a filename for the output CSV',
                'game.db.csv'
            )            
            ->setAliases(['export'])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storedItems = $this->ownedItemsManager->getLocalGameData();
        $storedItemIds = array_map(fn (GameDetail $detail) => $detail->id, $storedItems);        
        $progress = $io->createProgressBar();
        $progress->setMessage('Starting...');   
        $progress->setFormat("%elapsed:6s%/%estimated:-6s%\t[%bar%] %percent:3s%%\t%current%/%max%\t%message%");  
        $progress->setMaxSteps(count($storedItems));
        $progress->setProgress(0);  

        $ExportBuffer = [];
        foreach ($this->getTypes($input) as $type) {
            foreach ($storedItems as $item) {
                $title = $item->title;
                $totalItemDownloads = count($item->downloads);
                $currentItemDownload = 1;
                foreach ($item->downloads as $download) {
                    $downloadFilename = $this->downloadManager->getFilename($download);
                    $progress->setMessage("($currentItemDownload/$totalItemDownloads) $downloadFilename");
                    array_push($ExportBuffer, array($title,$download->language,$download->name,$download->url,$downloadFilename,$download->size,$download->platform,$download->md5));
                    $currentItemDownload++;
                }
                $progress->advance();
            }
        }
        
        $outputFile = $input->getOption('filename');
        $progress->setMessage("Writing $outputFile ...");
        try {
            # League/CSV can't create Excel-compatible files correctly, 
            #   create manually and set it to append mode:
            $fp = fopen($outputFile, "w");
            fwrite($fp,ByteSequence::BOM_UTF8);
            fclose($fp);
            $writer = Writer::createFromPath($outputFile, 'a+');

            # Configure for Excel
            $writer->setNewline("\r\n");
            $writer->setDelimiter(",");

            # Inject Data
            $writer->insertOne(array('Title','Language','Name','URL','Filename','SizeBYTES','Platform','MD5'));
            $writer->insertAll($ExportBuffer);
        } catch (Throwable $e) {
            $io->error("Export failed: $e");
            return self::FAILURE;
        }
        $progress->finish();
        $io->newLine();
        $io->success('Local data successfully exported.');

        return self::SUCCESS;
    }

    /**
     * @return array<MediaType>
     */
    private function getTypes(InputInterface $input): array
    {
        $result = [];
        if (!count($result)) {
            $result = [MediaType::Game/*MediaType::Movie*/];
        }

        return $result;
    }
}
