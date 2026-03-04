<?php

namespace App\Service\webhook;

use App\Entity\Rooms;
use App\Entity\RoomStatus;
use App\Entity\RoomStatusParticipant;
use Doctrine\ORM\EntityManagerInterface;

class RoomStatusFrontendService
{
    private $em;
    /**
     * @var array<int, bool>
     */
    private array $createdRoomStatusCache = [];

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->em = $entityManager;
    }

    public function isRoomCreated(Rooms $rooms)
    {
        if ($rooms->getId() && array_key_exists($rooms->getId(), $this->createdRoomStatusCache)) {
            return $this->createdRoomStatusCache[$rooms->getId()];
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

    public function numberOfOccupants(Rooms $rooms)
    {
        $parts = $this->em->getRepository(RoomStatusParticipant::class)->findOccupantsOfRoom($rooms);
        return $parts;
    }

    public function isRoomClosed(Rooms $rooms): bool
    {
        $status = $this->em->getRepository(RoomStatus::class)->findBy(['room' => $rooms]);

        if (sizeof($status) === 0) {
            return false;
        }
        if (!$rooms->getStart()) {
            return false;
        }
        foreach ($status as $data) {
            if ($data->getDestroyed() !== true) {
                return false;
            }
        }
        foreach ($status as $data) {
            if ($data->getDestroyedUtc() > $rooms->getStartUtc()) {
                return true;
            }
        }

        return false;
    }
}
