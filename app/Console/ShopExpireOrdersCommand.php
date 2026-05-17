<?php
declare(strict_types=1);

namespace App\Console;

use App\Services\ShopService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Expiruje nezaplacené objednávky (starší než konfigurovatelný počet hodin).
 *
 * Nastavte cron:
 *///   */30 * * * * cd /var/www/virtualniazyl && php bin/console app:shop-expire-orders

class ShopExpireOrdersCommand extends Command
{
    protected static $defaultName = 'app:shop-expire-orders';

    private ShopService $shopService;

    public function __construct(ShopService $shopService)
    {
        parent::__construct();
        $this->shopService = $shopService;
    }

    protected function configure(): void
    {
        $this->setDescription('Expiruje nezaplacené objednávky.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->shopService->expireUnpaidOrders();
        $output->writeln(sprintf(
            '<info>Hotovo.</info> Expirovaných objednávek: %d',
            $count
        ));
        return Command::SUCCESS;
    }
}
