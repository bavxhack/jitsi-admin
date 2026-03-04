<?php

namespace App\Service;

use App\Entity\Rooms;
use Doctrine\DBAL\Connection;

class DashboardRoomMetaService
{
    /**
     * @var array<int, int>
     */
    private array $invitedCountByRoomId = [];

    /**
     * @var array<int, string>
     */
    private array $mailToByRoomId = [];

    /**
     * @var array<int, bool>
     */
    private array $hasReportDataByRoomId = [];

    /**
     * @var array<int, bool>
     */
    private array $hasUploadedRecordingsByRoomId = [];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param Rooms[] $rooms
     */
    public function preloadRooms(array $rooms): void
    {
        $roomIds = [];
        foreach ($rooms as $room) {
            if (!$room instanceof Rooms || !$room->getId()) {
                continue;
            }
            $roomId = $room->getId();
            if (!array_key_exists($roomId, $this->invitedCountByRoomId)) {
                $roomIds[] = $roomId;
            }
        }

        $roomIds = array_values(array_unique($roomIds));
        if ($roomIds === []) {
            return;
        }

        $this->preloadUsers($roomIds);
        $this->preloadRoomStatus($roomIds);
        $this->preloadUploadedRecordings($roomIds);
    }

    public function invitedCount(Rooms $room): int
    {
        $roomId = $room->getId();
        if (!$roomId) {
            return 0;
        }

        return $this->invitedCountByRoomId[$roomId] ?? 0;
    }

    public function mailTo(Rooms $room): string
    {
        $roomId = $room->getId();
        if (!$roomId) {
            return '';
        }

        return $this->mailToByRoomId[$roomId] ?? '';
    }

    public function hasReportData(Rooms $room): bool
    {
        $roomId = $room->getId();
        if (!$roomId) {
            return false;
        }

        return $this->hasReportDataByRoomId[$roomId] ?? false;
    }

    public function hasUploadedRecordings(Rooms $room): bool
    {
        $roomId = $room->getId();
        if (!$roomId) {
            return false;
        }

        return $this->hasUploadedRecordingsByRoomId[$roomId] ?? false;
    }

    /**
     * @param int[] $roomIds
     */
    private function preloadUsers(array $roomIds): void
    {
        $rows = $this->connection->executeQuery(
            'SELECT ru.rooms_id AS room_id, u.email AS email
               FROM rooms_user ru
               INNER JOIN fos_user u ON u.id = ru.user_id
              WHERE ru.rooms_id IN (?)
              ORDER BY ru.rooms_id ASC, u.email ASC',
            [$roomIds],
            [Connection::PARAM_INT_ARRAY]
        )->fetchAllAssociative();

        foreach ($roomIds as $roomId) {
            $this->invitedCountByRoomId[$roomId] = 0;
            $this->mailToByRoomId[$roomId] = '';
        }

        foreach ($rows as $row) {
            $roomId = (int)$row['room_id'];
            $email = (string)$row['email'];
            $this->invitedCountByRoomId[$roomId]++;
            $this->mailToByRoomId[$roomId] = $this->mailToByRoomId[$roomId] === ''
                ? $email
                : $this->mailToByRoomId[$roomId] . ';' . $email;
        }
    }

    /**
     * @param int[] $roomIds
     */
    private function preloadRoomStatus(array $roomIds): void
    {
        $rows = $this->connection->executeQuery(
            'SELECT DISTINCT room_id FROM room_status WHERE room_id IN (?)',
            [$roomIds],
            [Connection::PARAM_INT_ARRAY]
        )->fetchFirstColumn();

        $roomIdLookup = array_fill_keys(array_map('intval', $rows), true);
        foreach ($roomIds as $roomId) {
            $this->hasReportDataByRoomId[$roomId] = isset($roomIdLookup[$roomId]);
        }
    }

    /**
     * @param int[] $roomIds
     */
    private function preloadUploadedRecordings(array $roomIds): void
    {
        $rows = $this->connection->executeQuery(
            'SELECT DISTINCT room_id FROM uploaded_recording WHERE room_id IN (?)',
            [$roomIds],
            [Connection::PARAM_INT_ARRAY]
        )->fetchFirstColumn();

        $roomIdLookup = array_fill_keys(array_map('intval', $rows), true);
        foreach ($roomIds as $roomId) {
            $this->hasUploadedRecordingsByRoomId[$roomId] = isset($roomIdLookup[$roomId]);
        }
    }
}
