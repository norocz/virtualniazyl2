<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Azyl;
use Doctrine\ORM\EntityManagerInterface;

class AzylActivityFeedService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Returns a merged, date-sorted activity feed from the given azyls.
     * Each item: ['type', 'icon', 'label', 'title', 'subtitle', 'date', 'azyl', 'linkPresenter', 'linkId']
     *
     * @param  Azyl[] $azyls
     * @return array<int, array<string, mixed>>
     */
    public function getFeed(array $azyls, int $limit = 30): array
    {
        if (empty($azyls)) {
            return [];
        }

        $azylIds = array_map(fn(Azyl $a) => $a->getId(), $azyls);
        $items = [];

        // ── Novinky ──────────────────────────────────────────────────────
        $news = $this->em->createQuery(
            'SELECT n FROM App\Model\Orm\Entity\News n
             WHERE n.azyl IN (:ids) AND n.deleted = :false
             ORDER BY n.createdAt DESC'
        )->setParameter('ids', $azylIds)
         ->setParameter('false', false)
         ->setMaxResults($limit)
         ->getResult();

        foreach ($news as $n) {
            $items[] = [
                'type'          => 'news',
                'icon'          => 'bi-newspaper',
                'label'         => 'Novinka',
                'title'         => $n->getTitle(),
                'subtitle'      => null,
                'date'          => $n->getCreatedAt(),
                'azyl'          => $n->getAzyl(),
                'linkPresenter' => 'Home:azyl',
                'linkId'        => $n->getAzyl()->getId(),
            ];
        }

        // ── Události ─────────────────────────────────────────────────────
        $events = $this->em->createQuery(
            'SELECT e FROM App\Model\Orm\Entity\AzylEvent e
             WHERE e.azyl IN (:ids) AND e.isPublished = :true
             ORDER BY e.createdAt DESC'
        )->setParameter('ids', $azylIds)
         ->setParameter('true', true)
         ->setMaxResults($limit)
         ->getResult();

        foreach ($events as $e) {
            $items[] = [
                'type'          => 'event',
                'icon'          => 'bi-calendar-event',
                'label'         => 'Událost',
                'title'         => $e->getTitle(),
                'subtitle'      => $e->getDateFrom()->format('d.m.Y H:i'),
                'date'          => $e->getCreatedAt(),
                'azyl'          => $e->getAzyl(),
                'linkPresenter' => 'Home:event',
                'linkId'        => $e->getId(),
            ];
        }

        // ── Zvířata k adopci ─────────────────────────────────────────────
        $animals = $this->em->createQuery(
            'SELECT a FROM App\Model\Orm\Entity\Animal a
             WHERE a.azyl IN (:ids) AND a.toAdoption = :true AND a.isDeleted = :false AND a.reception IS NOT NULL
             ORDER BY a.reception DESC'
        )->setParameter('ids', $azylIds)
         ->setParameter('true', true)
         ->setParameter('false', false)
         ->setMaxResults($limit)
         ->getResult();

        foreach ($animals as $a) {
            $items[] = [
                'type'          => 'animal',
                'icon'          => 'bi-heart',
                'label'         => 'Zvíře k adopci',
                'title'         => $a->getName() ?? $a->getSpecies()->getName(),
                'subtitle'      => $a->getSpecies()->getName(),
                'date'          => $a->getReception(),
                'azyl'          => $a->getAzyl(),
                'linkPresenter' => 'Home:adopce',
                'linkId'        => $a->getId(),
            ];
        }

        // ── Produkty v eshopu ────────────────────────────────────────────
        $products = $this->em->createQuery(
            'SELECT p FROM App\Model\Orm\Entity\ShopProduct p
             WHERE p.azyl IN (:ids) AND p.isActive = :true AND p.isApproved = :true
             ORDER BY p.createdAt DESC'
        )->setParameter('ids', $azylIds)
         ->setParameter('true', true)
         ->setMaxResults($limit)
         ->getResult();

        foreach ($products as $p) {
            $items[] = [
                'type'          => 'product',
                'icon'          => 'bi-bag',
                'label'         => 'Produkt v eshopu',
                'title'         => $p->getName(),
                'subtitle'      => number_format((float) $p->getPrice(), 0, ',', ' ') . ' Kč',
                'date'          => $p->getCreatedAt(),
                'azyl'          => $p->getAzyl(),
                'linkPresenter' => 'Shop:product',
                'linkId'        => $p->getId(),
            ];
        }

        usort($items, fn($a, $b) => $b['date'] <=> $a['date']);

        return array_slice($items, 0, $limit);
    }
}
