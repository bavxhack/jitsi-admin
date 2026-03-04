<?php

namespace App\Twig;

use App\Entity\Rooms;
use App\Service\DashboardRoomMetaService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DashboardRoomMetaExtension extends AbstractExtension
{
    public function __construct(
        private DashboardRoomMetaService $dashboardRoomMetaService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboardInvitedCount', [$this, 'dashboardInvitedCount']),
            new TwigFunction('dashboardMailTo', [$this, 'dashboardMailTo']),
            new TwigFunction('dashboardHasReportData', [$this, 'dashboardHasReportData']),
            new TwigFunction('dashboardHasUploadedRecordings', [$this, 'dashboardHasUploadedRecordings']),
        ];
    }

    public function dashboardInvitedCount(Rooms $room): int
    {
        return $this->dashboardRoomMetaService->invitedCount($room);
    }

    public function dashboardMailTo(Rooms $room): string
    {
        return $this->dashboardRoomMetaService->mailTo($room);
    }

    public function dashboardHasReportData(Rooms $room): bool
    {
        return $this->dashboardRoomMetaService->hasReportData($room);
    }

    public function dashboardHasUploadedRecordings(Rooms $room): bool
    {
        return $this->dashboardRoomMetaService->hasUploadedRecordings($room);
    }
}
