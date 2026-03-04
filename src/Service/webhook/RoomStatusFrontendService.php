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

    /**
     * @var array<int, int>
     */
    private array $occupantsCountCache = [];

    /**
     * @var array<int, string[]>
     */
    private array $occupantsNamesCache = [];

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

    /**
     * @param Rooms[] $rooms
     */
    public function preloadOccupantsCountForRooms(array $rooms): void
    {
        $roomIds = [];
        foreach ($rooms as $room) {
            if (!$room instanceof Rooms || !$room->getId()) {
                continue;
            }

            $roomId = $room->getId();
            if (!array_key_exists($roomId, $this->occupantsCountCache)) {
                $roomIds[] = $roomId;
            }
        }

        $roomIds = array_values(array_unique($roomIds));
        if ($roomIds === []) {
            return;
        }

        $counts = $this->em->getRepository(RoomStatusParticipant::class)->findOccupantsCountByRoomIds($roomIds);
        foreach ($roomIds as $roomId) {
            $this->occupantsCountCache[$roomId] = $counts[$roomId] ?? 0;
        }
    }

    /**
     * @param Rooms[] $rooms
     */
    public function preloadOccupantsNamesForRooms(array $rooms): void
    {
        $roomIds = [];
        foreach ($rooms as $room) {
            if (!$room instanceof Rooms || !$room->getId()) {
                continue;
            }

            $roomId = $room->getId();
            if (!array_key_exists($roomId, $this->occupantsNamesCache)) {
                $roomIds[] = $roomId;
            }
        }

        $roomIds = array_values(array_unique($roomIds));
        if ($roomIds === []) {
            return;
        }

        $rows = $this->em->getConnection()->executeQuery(
            'SELECT rs.room_id as room_id, rsp.participant_name as participant_name
               FROM room_status_participant rsp
               INNER JOIN room_status rs ON rs.id = rsp.room_status_id
              WHERE rs.room_id IN (?)
                AND rsp.in_room = 1
                AND rs.destroyed IS NULL
              ORDER BY rs.room_id ASC, rsp.participant_name ASC',
            [$roomIds],
            [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
        )->fetchAllAssociative();

        foreach ($roomIds as $roomId) {
            $this->occupantsNamesCache[$roomId] = [];
        }

        foreach ($rows as $row) {
            $this->occupantsNamesCache[(int)$row['room_id']][] = (string)$row['participant_name'];
        }
    }

    /**
     * @return string[]
     */
    public function occupantsNames(Rooms $rooms): array
    {
        if ($rooms->getId() && array_key_exists($rooms->getId(), $this->occupantsNamesCache)) {
            return $this->occupantsNamesCache[$rooms->getId()];
        }

        $names = [];
        foreach ($this->numberOfOccupants($rooms) as $occupant) {
            $names[] = $occupant->getParticipantName();
        }

        if ($rooms->getId()) {
            $this->occupantsNamesCache[$rooms->getId()] = $names;
        }

        return $names;
    }

    public function occupantsCount(Rooms $rooms): int
    {
        if ($rooms->getId() && array_key_exists($rooms->getId(), $this->occupantsCountCache)) {
            return $this->occupantsCountCache[$rooms->getId()];
        }

        $parts = $this->numberOfOccupants($rooms);
        $count = sizeof($parts);
        if ($rooms->getId()) {
            $this->occupantsCountCache[$rooms->getId()] = $count;
        }

        return $count;
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
