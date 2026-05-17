<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\ShopDocument;
use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Enums\ShopDocumentTypeEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class ShopDocumentRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, string $class = ShopDocument::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));
    }

    public function findByNumber(string $documentNumber): ?ShopDocument
    {
        return $this->findOneBy(['documentNumber' => $documentNumber]);
    }

    /**
     * @return ShopDocument[]
     */
    public function findByOrder(ShopOrder $order, ?ShopDocumentTypeEnum $type = null): array
    {
        $criteria = ['order' => $order];
        if ($type !== null) {
            $criteria['documentType'] = $type;
        }
        return $this->findBy($criteria, ['issuedAt' => 'ASC']);
    }

    public function findOneByOrderAndType(ShopOrder $order, ShopDocumentTypeEnum $type): ?ShopDocument
    {
        return $this->findOneBy(['order' => $order, 'documentType' => $type]);
    }

    /**
     * Atomické vygenerování dalšího čísla v sekvenci.
     * Používá SELECT FOR UPDATE pro thread-safe inkrement.
     */
    public function getNextSequenceNumber(int $year, ShopDocumentTypeEnum $type): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->beginTransaction();
        try {
            // Upsert + fetch v jedné transakci
            $conn->executeStatement(
                'INSERT INTO shop_document_sequences (sequence_year, document_type, last_number)
                 VALUES (:y, :t, 1)
                 ON DUPLICATE KEY UPDATE last_number = last_number + 1',
                ['y' => $year, 't' => $type->value]
            );

            $last = (int)$conn->fetchOne(
                'SELECT last_number FROM shop_document_sequences
                 WHERE sequence_year = :y AND document_type = :t',
                ['y' => $year, 't' => $type->value]
            );

            $conn->commit();
            return $last;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    public function save(ShopDocument $doc): void
    {
        $this->getEntityManager()->persist($doc);
        $this->getEntityManager()->flush();
    }
}
