<?php
declare(strict_types=1);

namespace App\Components\Datagrids;

use App\Model\Orm\Repository\PaymentsInRepository;
use Ublaboo\DataGrid\DataGrid;
use Ublaboo\DataGrid\Exception\DataGridException;
use Ublaboo\DataGrid\Localization\SimpleTranslator;

class BankImportsDatagridFactory
{
    public function __construct(
        private readonly PaymentsInRepository $paymentsInRepository
    ) {}

    /** @throws DataGridException */
    public function create(): DataGrid
    {
        $grid = new DataGrid();
        $grid->setTranslator(new SimpleTranslator([
            'ublaboo_datagrid.no_item_found_reset' => 'Žádné záznamy. Filtr můžete vynulovat',
            'ublaboo_datagrid.no_item_found'       => 'Žádné záznamy.',
            'ublaboo_datagrid.here'                => 'zde',
            'ublaboo_datagrid.items'               => 'Položky',
            'ublaboo_datagrid.all'                 => 'všechny',
            'ublaboo_datagrid.from'                => 'z',
            'ublaboo_datagrid.reset_filter'        => 'Resetovat filtr',
            'ublaboo_datagrid.group_actions'       => 'Hromadné akce',
            'ublaboo_datagrid.action'              => 'Akce',
            'ublaboo_datagrid.previous'            => 'Předchozí',
            'ublaboo_datagrid.next'                => 'Další',
            'ublaboo_datagrid.choose'              => 'Vyberte',
            'ublaboo_datagrid.execute'             => 'Provést',
        ]));

        $grid->setDataSource($this->paymentsInRepository->findAllOrdered());
        $grid->setDefaultPerPage(50);
        $grid->setItemsPerPageList([25, 50, 100, 200]);

        $grid->addColumnDateTime('datum', 'Datum')
            ->setFormat('d.m.Y H:i')
            ->setSortable()
            ->setFilterDateRange();

        $grid->addColumnText('objem', 'Částka (Kč)')
            ->setRenderer(fn($row) => number_format($row->getObjem(), 2, ',', ' ') . ' Kč')
            ->setSortable()
            ->setFilterText();

        $grid->addColumnText('nazevProtiuctu', 'Protiúčet')
            ->setRenderer(fn($row) =>
                $row->getNazevProtiuctu()
                . ($row->getProtiucet() ? ' (' . $row->getProtiucet() . '/' . $row->getKodBanky() . ')' : '')
            )
            ->setFilterText();

        $grid->addColumnText('vs', 'VS')
            ->setSortable()
            ->setFilterText();

        $grid->addColumnText('recipientMessage', 'Zpráva')
            ->setRenderer(fn($row) => $row->getRecipientMessage() ?? '—')
            ->setFilterText();

        $grid->addColumnText('_paired', 'Párování')
            ->setRenderer(function ($row) {
                if ($row->isPaired()) {
                    $p = $row->getPairedPayment();
                    return '<span class="badge bg-success">Spárováno</span>'
                        . '<br><small class="text-muted">VS ' . htmlspecialchars((string)$p->getVariableSymbol()) . '</small>';
                }
                return '<span class="badge bg-warning text-dark">Nespárováno</span>';
            })
            ->setTemplateEscaping(false);

        $grid->addFilterSelect('_filter_paired', 'Stav:', [
            ''  => 'Vše',
            '0' => 'Nespárováno',
            '1' => 'Spárováno',
        ])->setCondition(function ($qb, $value) {
            if ($value === '0') {
                $qb->andWhere('p.pairedPayment IS NULL');
            } elseif ($value === '1') {
                $qb->andWhere('p.pairedPayment IS NOT NULL');
            }
        });

        $grid->addAction('detail', 'Detail / Spárovat', 'SuperAdmin:bankimport')
            ->setClass('btn btn-sm btn-outline-primary');

        return $grid;
    }
}
