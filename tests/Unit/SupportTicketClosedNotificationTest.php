<?php

namespace Tests\Unit;

use App\Events\Support\TicketClosed;
use App\Listeners\SupportEventSubscriber;
use App\Ticket;
use App\User;
use Tests\TestCase;

class SupportTicketClosedNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendTicketClosedNotificationTwice()
    {
        $ticket = $this->ticketWithNotificationUser($user, 'closed-ticket-id');
        $event = new TicketClosed($ticket);
        $subscriber = new SupportEventSubscriber();

        $subscriber->onTicketClosed($event);
        $subscriber->onTicketClosed($event);

        $this->assertSame(1, $user->notificationsSent);
        $this->assertSame('ticket-closed:closed-ticket-id', $user->lastDeliveryKey);
        $this->assertSame([
            'Your support ticket has been closed by administrator',
            'profile.tickets',
            serialize(['ticket' => $ticket->id]),
        ], $user->lastNotification);
    }

    public function testDifferentTicketsUseDifferentDeliveryIdentities()
    {
        $subscriber = new SupportEventSubscriber();
        $firstTicket = $this->ticketWithNotificationUser($user, 'first-ticket-id');
        $secondTicket = $this->ticketWithExistingNotificationUser($user, 'second-ticket-id');

        $subscriber->onTicketClosed(new TicketClosed($firstTicket));
        $subscriber->onTicketClosed(new TicketClosed($secondTicket));

        $this->assertSame(2, $user->notificationsSent);
        $this->assertSame([
            'ticket-closed:first-ticket-id',
            'ticket-closed:second-ticket-id',
        ], array_keys($user->persistedNotifications));
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateTicketClosedNotification()
    {
        $ticket = $this->ticketWithNotificationUser($user, 'closed-ticket-id');
        $event = new TicketClosed($ticket);
        $subscriber = new SupportEventSubscriber();
        $user->failAfterPersisting = true;

        try {
            $subscriber->onTicketClosed($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $user->failAfterPersisting = false;
        $subscriber->onTicketClosed($event);

        $this->assertSame(1, $user->notificationsSent);
        $this->assertSame(['ticket-closed:closed-ticket-id'], array_keys($user->persistedNotifications));
    }

    private function ticketWithNotificationUser(&$user, $ticketId)
    {
        $user = new SupportTicketClosedNotificationUserDouble();

        return $this->ticketWithExistingNotificationUser($user, $ticketId);
    }

    private function ticketWithExistingNotificationUser($user, $ticketId)
    {
        $ticket = new Ticket();
        $ticket->id = $ticketId;
        $ticket->setRelation('user', $user);

        return $ticket;
    }
}

class SupportTicketClosedNotificationUserDouble extends User
{
    public $failAfterPersisting = false;
    public $lastDeliveryKey;
    public $lastNotification = [];
    public $notificationsSent = 0;
    public $persistedNotifications = [];

    public function notifyOnce(
        string $deliveryKey,
        string $content,
        ?string $routeName = null,
        ?string $routeParams = null
    ): bool {
        if (array_key_exists($deliveryKey, $this->persistedNotifications)) {
            return false;
        }

        $this->persistedNotifications[$deliveryKey] = [
            'description' => $content,
            'route_name' => $routeName,
            'route_params' => $routeParams,
        ];
        $this->notificationsSent++;
        $this->lastDeliveryKey = $deliveryKey;
        $this->lastNotification = [$content, $routeName, $routeParams];

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }

        return true;
    }
}
