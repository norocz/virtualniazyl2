<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Adoption;
use App\Model\Orm\Entity\Animal;
use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\Collections;
use App\Model\Orm\Entity\Conversations;
use App\Model\Orm\Entity\Messages;
use App\Model\Orm\Entity\Payments;
use App\Model\Orm\Entity\Users;
use App\Model\Orm\Enums\AdoptionsTypeEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Agreguje data pro admin dashboard.
 *
 * Filosofie: Všechny náročné query jsou tady (na jednom místě),
 * aby AdminPresenter::renderDefault() byl štíhlý.
 *
 * Výstupy jsou jednoduchá pole připravená pro Chart.js v šabloně.
 */
class DashboardStatsService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    // =============================================================
    // ADOPCE - aktivita systému
    // =============================================================

    /**
     * Počty adopcí podle typu za posledních N dnů.
     *
     * @return array{
     *   virtualni: int,
     *   docasna: int,
     *   predadopce: int,
     *   plna: int,
     *   total: int,
     * }
     */
    public function getAdoptionsByType(int $lastDays = 30): array
    {
        $from = new DateTimeImmutable('-' . $lastDays . ' days');

        $rows = $this->em->createQueryBuilder()
            ->select('a.adoptionType AS type', 'COUNT(a.id) AS cnt')
            ->from(Adoption::class, 'a')
            ->innerJoin(Animal::class, 'an', 'WITH', 'an.id = a.animal')
            ->where('a.createdAt >= :from')
            ->andWhere('a.deleted = false')
            ->groupBy('a.adoptionType')
            ->setParameter('from', $from)
            ->getQuery()
            ->getResult();

        $result = [
            'virtualni'  => 0,
            'docasna'    => 0,
            'predadopce' => 0,
            'plna'       => 0,
            'total'      => 0,
        ];
        $map = [
            AdoptionsTypeEnum::VIRTUAL_ADOPTION_TYPE  => 'virtualni',
            AdoptionsTypeEnum::TEMP_ADOPTION_TYPE     => 'docasna',
            AdoptionsTypeEnum::PREADOPT_ADOPTION_TYPE => 'predadopce',
            AdoptionsTypeEnum::FULL_ADOPTION_TYPE     => 'plna',
        ];

        foreach ($rows as $r) {
            $key = $map[$r['type']] ?? null;
            if ($key !== null) {
                $result[$key] = (int)$r['cnt'];
                $result['total'] += (int)$r['cnt'];
            }
        }
        return $result;
    }

    /**
     * Měsíční trend adopcí (posledních N měsíců).
     *
     * @return array<int, array{month: string, virtualni: int, docasna: int, predadopce: int, plna: int}>
     */
    public function getAdoptionsMonthlyTrend(int $lastMonths = 12): array
    {
        $from = (new DateTimeImmutable())->modify('-' . $lastMonths . ' months')
            ->modify('first day of this month')->setTime(0, 0);

        $sql = "
            SELECT
                DATE_FORMAT(a.created_at, '%Y-%m') AS month_key,
                an.adoption_type AS type,
                COUNT(a.id) AS cnt
            FROM adoptions a
            INNER JOIN animals an ON an.id = a.animal_id
            WHERE a.created_at >= :from
              AND a.deleted = 0
            GROUP BY month_key, type
            ORDER BY month_key ASC
        ";
        $rows = $this->em->getConnection()->fetchAllAssociative($sql, ['from' => $from->format('Y-m-d H:i:s')]);

        // Naplnit prázdné měsíce nulami
        $result = [];
        $current = clone $from;
        $end = new DateTimeImmutable('first day of next month');
        while ($current < $end) {
            $key = $current->format('Y-m');
            $result[$key] = [
                'month'      => $key,
                'virtualni'  => 0,
                'docasna'    => 0,
                'predadopce' => 0,
                'plna'       => 0,
            ];
            $current = $current->modify('+1 month');
        }

        $map = [
            AdoptionsTypeEnum::VIRTUAL_ADOPTION_TYPE  => 'virtualni',
            AdoptionsTypeEnum::TEMP_ADOPTION_TYPE     => 'docasna',
            AdoptionsTypeEnum::PREADOPT_ADOPTION_TYPE => 'predadopce',
            AdoptionsTypeEnum::FULL_ADOPTION_TYPE     => 'plna',
        ];

        foreach ($rows as $r) {
            if (!isset($result[$r['month_key']])) {
                continue;
            }
            $key = $map[$r['type']] ?? null;
            if ($key !== null) {
                $result[$r['month_key']][$key] = (int)$r['cnt'];
            }
        }

        return array_values($result);
    }

    // =============================================================
    // ZVÍŘATA - kolik je k adopci, kolik už je v novém domově
    // =============================================================

    public function getAnimalsStats(): array
    {
        $total = (int)$this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Animal::class, 'a')
            ->where('a.isDeleted = false')
            ->getQuery()
            ->getSingleScalarResult();

        $toAdoption = (int)$this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Animal::class, 'a')
            ->where('a.isDeleted = false')
            ->andWhere('a.toAdoption = true')
            ->andWhere('a.adopted = false')
            ->getQuery()
            ->getSingleScalarResult();

        $adopted = (int)$this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Animal::class, 'a')
            ->where('a.isDeleted = false')
            ->andWhere('a.adopted = true')
            ->getQuery()
            ->getSingleScalarResult();

        // Podle druhů
        $bySpecies = $this->em->getConnection()->fetchAllAssociative("
            SELECT s.name AS species, COUNT(a.id) AS cnt
            FROM animals a
            INNER JOIN species s ON s.id = a.species_id
            WHERE a.is_deleted = 0 AND a.to_adoption = 1 AND a.adopted = 0
            GROUP BY s.name
            ORDER BY cnt DESC
        ");

        return [
            'total'        => $total,
            'to_adoption'  => $toAdoption,
            'adopted'      => $adopted,
            'by_species'   => $bySpecies,
        ];
    }

    // =============================================================
    // SBÍRKY
    // =============================================================

    public function getCollectionsStats(): array
    {
        $active = (int)$this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Collections::class, 'c')
            ->where('c.isActive = true')
            ->andWhere('c.endingAt >= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        $total = (int)$this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Collections::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();

        // Celkem vybráno (součet všech Payments napojených na Collections)
        $totalRaised = (float)$this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.pay), 0)')
            ->from(Payments::class, 'p')
            ->innerJoin('p.collections', 'c')
            ->where('p.payedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        // Top 5 nejúspěšnějších sbírek
        $topCollections = $this->em->getConnection()->fetchAllAssociative("
            SELECT
                c.id AS id,
                c.collection_name AS name,
                c.collection_key AS `key`,
                c.minimal_amount AS goal,
                COALESCE(SUM(p.pay), 0) AS raised,
                COUNT(DISTINCT p.id) AS payments_count
            FROM collections c
            LEFT JOIN payments p ON p.collections_id = c.id AND p.payed_at IS NOT NULL
            GROUP BY c.id
            HAVING raised > 0
            ORDER BY raised DESC
            LIMIT 5
        ");

        return [
            'active_count'      => $active,
            'total_count'       => $total,
            'total_raised'      => $totalRaised,
            'top_collections'   => $topCollections,
        ];
    }

    // =============================================================
    // AZYLY - aktivita
    // =============================================================

    /**
     * Top azyly podle aktivity (nová zvířata + adopce + sbírky za poslední měsíc).
     *
     * @return array<int, array{id: int, name: string, new_animals: int, adoptions: int, collections: int, score: int}>
     */
    public function getMostActiveAzyls(int $lastDays = 30, int $limit = 10): array
    {
        $from = (new DateTimeImmutable('-' . $lastDays . ' days'))->format('Y-m-d H:i:s');

        // Jedním dotazem sloučíme vše co nás zajímá
        $sql = "
            SELECT
                az.id AS id,
                az.azyl_name AS name,
                (SELECT COUNT(*) FROM animals an
                    WHERE an.azyl_id = az.id AND an.is_deleted = 0) AS total_animals,
                (SELECT COUNT(*) FROM animals an
                    WHERE an.azyl_id = az.id AND an.is_deleted = 0
                    AND an.id IN (
                        SELECT animal_id FROM animal_history
                        WHERE created_at >= :from
                    )) AS new_animals,
                (SELECT COUNT(*) FROM adoptions a
                    INNER JOIN animals an ON an.id = a.animal_id
                    WHERE an.azyl_id = az.id AND a.created_at >= :from
                    AND a.deleted = 0) AS adoptions,
                (SELECT COUNT(*) FROM collections c
                    WHERE c.azyl_id = az.id AND c.created_at >= :from) AS collections
            FROM azyls az
            ORDER BY (new_animals + adoptions * 2 + collections * 3) DESC
            LIMIT :limit
        ";

        try {
            $rows = $this->em->getConnection()->fetchAllAssociative($sql, [
                'from'  => $from,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            // Pokud animal_history tabulka neexistuje, fallback bez ní
            $sql = "
                SELECT
                    az.id AS id,
                    az.azyl_name AS name,
                    (SELECT COUNT(*) FROM animals an
                        WHERE an.azyl_id = az.id AND an.is_deleted = 0) AS new_animals,
                    (SELECT COUNT(*) FROM adoptions a
                        INNER JOIN animals an ON an.id = a.animal_id
                        WHERE an.azyl_id = az.id AND a.created_at >= :from
                        AND a.deleted = 0) AS adoptions,
                    (SELECT COUNT(*) FROM collections c
                        WHERE c.azyl_id = az.id AND c.created_at >= :from) AS collections
                FROM azyls az
                ORDER BY (new_animals + adoptions * 2 + collections * 3) DESC
                LIMIT $limit
            ";
            $rows = $this->em->getConnection()->fetchAllAssociative($sql, ['from' => $from]);
        }

        return array_map(static fn($r) => [
            'id'          => (int)$r['id'],
            'name'        => $r['name'] ?? '—',
            'new_animals' => (int)($r['new_animals'] ?? 0),
            'adoptions'   => (int)$r['adoptions'],
            'collections' => (int)$r['collections'],
            'score'       => (int)($r['new_animals'] ?? 0) + (int)$r['adoptions'] * 2 + (int)$r['collections'] * 3,
        ], $rows);
    }

    public function getAzylsOverview(): array
    {
        $total = (int)$this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Azyl::class, 'a')
            ->getQuery()
            ->getSingleScalarResult();

        // azyly bez aktivity za 30 dní (varování pro admina)
        $inactive30Days = $this->em->getConnection()->fetchAllAssociative("
            SELECT az.id, az.azyl_name AS name
            FROM azyls az
            WHERE NOT EXISTS (
                SELECT 1 FROM adoptions a
                INNER JOIN animals an ON an.id = a.animal_id
                WHERE an.azyl_id = az.id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            )
            AND NOT EXISTS (
                SELECT 1 FROM collections c
                WHERE c.azyl_id = az.id AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            )
            LIMIT 20
        ");

        return [
            'total'            => $total,
            'inactive_30_days' => $inactive30Days,
        ];
    }

    // =============================================================
    // KOMUNIKACE
    // =============================================================

    public function getMessagingStats(int $lastDays = 7): array
    {
        $from = new DateTimeImmutable('-' . $lastDays . ' days');

        $totalMessages = (int)$this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Messages::class, 'm')
            ->where('m.createdAt >= :from')
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleScalarResult();

        $activeConversations = (int)$this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Conversations::class, 'c')
            ->where('c.lastMessage >= :from')
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleScalarResult();

        $blockedConversations = (int)$this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Conversations::class, 'c')
            ->where('c.block = true')
            ->getQuery()
            ->getSingleScalarResult();

        $bannedUsers = (int)$this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(Users::class, 'u')
            ->where('u.baned = true')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'messages_total'        => $totalMessages,
            'active_conversations'  => $activeConversations,
            'blocked_conversations' => $blockedConversations,
            'banned_users'          => $bannedUsers,
        ];
    }

    // =============================================================
    // Geografická distribuce - kde jsou azyly
    // =============================================================

    /**
     * Pro mapu - kde jsou azyly.
     *
     * @return array<int, array{id:int, name:string, lat:float, lon:float, animals:int}>
     */
    public function getAzylsGeoDistribution(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative("
            SELECT
                az.id AS id,
                az.azyl_name AS name,
                c.latitude AS lat,
                c.longitude AS lon,
                (SELECT COUNT(*) FROM animals an
                    WHERE an.azyl_id = az.id AND an.is_deleted = 0 AND an.to_adoption = 1) AS animals
            FROM azyls az
            LEFT JOIN citys c ON c.id = az.city
            WHERE c.latitude IS NOT NULL AND c.longitude IS NOT NULL
        ");

        return array_map(static fn($r) => [
            'id'      => (int)$r['id'],
            'name'    => $r['name'] ?? '—',
            'lat'     => (float)$r['lat'],
            'lon'     => (float)$r['lon'],
            'animals' => (int)$r['animals'],
        ], $rows);
    }
}
