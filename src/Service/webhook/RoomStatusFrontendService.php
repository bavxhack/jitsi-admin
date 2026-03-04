<?php

namespace App\Service\webhook;

use App\Entity\Rooms;
use App\Entity\RoomStatus;
use App\Entity\RoomStatusParticipant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

class RoomStatusFrontendService
{
    private $em;
    /**
     * @var array<int, bool>
     */
    private array $createdRoomStatusCache = [];

    /**
     * @var array<int, bool>
     */
    private array $closedRoomStatusCache = [];

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->em = $entityManager;
    }

    public function isRoomCreated(Rooms $rooms)
    {
        if ($rooms->getId() && array_key_exists($rooms->getId(), $this->createdRoomStatusCache)) {
            return $this->createdRoomStatusCache[$rooms->getId()];
        }

        $roomStatuses = $rooms->getRoomstatuses();
        if (!$roomStatuses instanceof PersistentCollection || $roomStatuses->isInitialized()) {
            $isCreated = false;
            foreach ($roomStatuses as $roomStatus) {
                if ($roomStatus->getCreated() === true && $roomStatus->getDestroyed() !== true) {
                    $isCreated = true;
                    break;
                }
            }

            if ($rooms->getId()) {
                $this->createdRoomStatusCache[$rooms->getId()] = $isCreated;
            }

            return $isCreated;
        }

        $roomStatus = $this->em->getRepository(RoomStatus::class)->findCreatedRooms($rooms);
        $isCreated = (bool)$roomStatus;

        if ($rooms->getId()) {
            $this->createdRoomStatusCache[$rooms->getId()] = $isCreated;
        }

        return $isCreated;
    }

    /**
     * @param Rooms[] $rooms
     */
    public function preloadCreatedStatusForRooms(array $rooms): void
    {
        $roomIds = [];
        foreach ($rooms as $room) {
            if (!$room instanceof Rooms || !$room->getId()) {
                continue;
            }

            $roomId = $room->getId();
            if (!array_key_exists($roomId, $this->createdRoomStatusCache)) {
                $roomIds[] = $roomId;
            }
        }

        $roomIds = array_values(array_unique($roomIds));
        if ($roomIds === []) {
            return;
        }

        $createdRoomIds = $this->em->getRepository(RoomStatus::class)->findCreatedRoomIdsByRoomIds($roomIds);
        $createdRoomIds = array_flip($createdRoomIds);

        foreach ($roomIds as $roomId) {
            $this->createdRoomStatusCache[$roomId] = isset($createdRoomIds[$roomId]);
        }
    }


    /**
     * @param Rooms[] $rooms
     */
    public function preloadClosedStatusForRooms(array $rooms): void
    {
        $roomIds = [];
        $roomStartUtc = [];
        foreach ($rooms as $room) {
            if (!$room instanceof Rooms || !$room->getId()) {
                continue;
            }
            $roomId = $room->getId();
            if (array_key_exists($roomId, $this->closedRoomStatusCache)) {
                continue;
            }
            $roomIds[] = $roomId;
            $roomStartUtc[$roomId] = $room->getStartUtc()?->getTimestamp();
        }

        $roomIds = array_values(array_unique($roomIds));
        if ($roomIds === []) {
            return;
        }

        $rows = $this->em->getConnection()->executeQuery(
            'SELECT room_id, destroyed, destroyed_at FROM room_status WHERE room_id IN (?)',
            [$roomIds],
            [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
        )->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['room_id']][] = $row;
        }

        foreach ($roomIds as $roomId) {
            $statuses = $grouped[$roomId] ?? [];
            $isClosed = false;
            $startTimestamp = $roomStartUtc[$roomId] ?? null;
            if ($statuses !== [] && $startTimestamp) {
                $allDestroyed = true;
                $hasDestroyedAfterStart = false;
                foreach ($statuses as $status) {
                    if ((int)($status['destroyed'] ?? 0) !== 1) {
                        $allDestroyed = false;
                        break;
                    }
                    $destroyedAt = $status['destroyed_at'] ? strtotime((string)$status['destroyed_at']) : null;
                    if ($destroyedAt && $destroyedAt > $startTimestamp) {
                        $hasDestroyedAfterStart = true;
                    }
                }
                $isClosed = $allDestroyed && $hasDestroyedAfterStart;
            }
            $this->closedRoomStatusCache[$roomId] = $isClosed;
        }
    }

    public function numberOfOccupants(Rooms $rooms)
    {
        $parts = $this->em->getRepository(RoomStatusParticipant::class)->findOccupantsOfRoom($rooms);
        return $parts;
    }

    public function isRoomClosed(Rooms $rooms): bool
    {
        if ($rooms->getId() && array_key_exists($rooms->getId(), $this->closedRoomStatusCache)) {
            return $this->closedRoomStatusCache[$rooms->getId()];
        }

        $status = $rooms->getRoomstatuses();
        if ($status instanceof PersistentCollection && !$status->isInitialized()) {
            $status = $this->em->getRepository(RoomStatus::class)->findBy(['room' => $rooms]);
        }

        $isClosed = false;
        if (sizeof($status) !== 0 && $rooms->getStart()) {
            $isClosed = true;
            foreach ($status as $data) {
                if ($data->getDestroyed() !== true) {
                    $isClosed = false;
                    break;
                }
            }
            if ($isClosed) {
                $isClosed = false;
                foreach ($status as $data) {
                    if ($data->getDestroyedUtc() > $rooms->getStartUtc()) {
                        $isClosed = true;
                        break;
                    }
                }
            }
        }

        if ($rooms->getId()) {
            $this->closedRoomStatusCache[$rooms->getId()] = $isClosed;
        }

        return $isClosed;
    }
}
