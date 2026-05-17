<?php
declare(strict_types=1);

namespace App\Console;

use App\Services\OpenSearchService;
use App\Services\SearchIndexerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kompletní přebudování OpenSearch indexů z MySQL DB.
 *
 * Spuštění:
 *   php bin/console app:reindex-opensearch
 */
class ReindexOpenSearchCommand extends Command
{
    protected static $defaultName = 'app:reindex-opensearch';

    private OpenSearchService $openSearch;
    private SearchIndexerService $indexer;

    public function __construct(OpenSearchService $openSearch, SearchIndexerService $indexer)
    {
        parent::__construct();
        $this->openSearch = $openSearch;
        $this->indexer = $indexer;
    }

    protected function configure(): void
    {
        $this->setDescription('Přebuduje kompletně OpenSearch indexy z MySQL DB.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->openSearch->isEnabled()) {
            $output->writeln('<e>OpenSearch je v konfiguraci vypnutý (parameters.opensearch.enabled = false).</e>');
            return Command::FAILURE;
        }

        $output->writeln('Přebudovávám OpenSearch indexy...');
        $counts = $this->indexer->reindexAll();

        $output->writeln(sprintf(
            '<info>Hotovo.</info> Měst: %d | Azylů: %d | Zvířátek: %d',
            $counts['cities'],
            $counts['azyls'],
            $counts['animals']
        ));

        return Command::SUCCESS;
    }
}
