<?php

namespace App\Repository;

use App\Entity\Rooms;
use App\Entity\RoomStatusParticipant;
use App\Entity\Server;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function Doctrine\ORM\QueryBuilder;

/**
 * @method RoomStatusParticipant|null find($id, $lockMode = null, $lockVersion = null)
 * @method RoomStatusParticipant|null findOneBy(array $criteria, array $orderBy = null)
 * @method RoomStatusParticipant[]    findAll()
 * @method RoomStatusParticipant[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RoomStatusParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomStatusParticipant::class);
    }

    // /**
    //  * @return RoomStatusParticipant[] Returns an array of RoomStatusParticipant objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?RoomStatusParticipant
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
    public function findOccupantsOfRoom(Rooms $rooms)
    {
        $qb = $this->createQueryBuilder('r');

        return $qb->innerJoin('r.roomStatus', 'roomStatus')
            ->innerJoin('roomStatus.room', 'room')
            ->andWhere('room = :room')
            ->andWhere('r.inRoom = true')
            ->andWhere($qb->expr()->isNull('roomStatus.destroyed'))
            ->setParameter('room', $rooms)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return RoomStatusParticipant[] Returns an array of RoomStatusParticipant objects
     */

    public function findActualParticipantsByServer(Server $server)
    {
        $qb = $this->createQueryBuilder('r');
        return $qb->innerJoin('r.roomStatus', 'roomStatus')
            ->innerJoin('roomStatus.room', 'room')
            ->innerJoin('room.server', 'server')
            ->andWhere('server = :server')
            ->andWhere(
                $qb->expr()->orX(
                    'roomStatus.destroyed = :false',
                    $qb->expr()->isNull('roomStatus.destroyed')
                )
            )
            ->andWhere('r.inRoom = :true')
            ->setParameter('server', $server)
            ->setParameter('false', false)
            ->setParameter('true', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return RoomStatusParticipant[] Returns an array of RoomStatusParticipant objects
     */

    public function findParticipantsByServer(Server $server, $startDate, $endDate)
    {
        $qb = $this->createQueryBuilder('r');
        return $qb->innerJoin('r.roomStatus', 'roomStatus')
            ->innerJoin('roomStatus.room', 'room')
            ->innerJoin('room.server', 'server')
            ->andWhere('server = :server')
            ->andWhere($qb->expr()->gte('r.enteredRoomAt', ':startDate'))
            ->andWhere($qb->expr()->lte('r.enteredRoomAt', ':endDate'))
            ->setParameter('server', $server)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $roomIds
     * @return array<int, int>
     */
    public function findOccupantsCountByRoomIds(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('participant');
        $rows = $qb->select('IDENTITY(roomStatus.room) as roomId, COUNT(participant.id) as occupantsCount')
            ->innerJoin('participant.roomStatus', 'roomStatus')
            ->andWhere($qb->expr()->in('roomStatus.room', ':roomIds'))
            ->andWhere('participant.inRoom = true')
            ->andWhere($qb->expr()->isNull('roomStatus.destroyed'))
            ->groupBy('roomStatus.room')
            ->setParameter('roomIds', $roomIds)
            ->getQuery()
            ->getScalarResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['roomId']] = (int)$row['occupantsCount'];
        }

        return $result;
    }

}
