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
        $ticket = $this->ticketWithNotificationUser($user);
        $event = new TicketClosed($ticket);
        $subscriber = new SupportEventSubscriber();

        $subscriber->onTicketClosed($event);
        $subscriber->onTicketClosed($event);

        $this->assertSame(1, $user->notificationsSent);
        $this->assertSame([
            'Your support ticket has been closed by administrator',
            'profile.tickets',
            serialize(['ticket' => $ticket->id]),
        ], $user->lastNotification);
        $this->assertSame([
            ['description', 'Your support ticket has been closed by administrator'],
            ['route_name', 'profile.tickets'],
            ['route_params', serialize(['ticket' => $ticket->id])],
        ], $user->lastNotificationQuery->filters);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateTicketClosedNotification()
    {
        $ticket = $this->ticketWithNotificationUser($user);
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
        $this->assertSame([
            'Your support ticket has been closed by administrator',
            'profile.tickets',
            serialize(['ticket' => $ticket->id]),
        ], $user->lastNotification);
    }

    private function ticketWithNotificationUser(&$user)
    {
        $ticket = new Ticket();
        $ticket->id = 'closed-ticket-id';

        $user = new SupportTicketClosedNotificationUserDouble();
        $ticket->setRelation('user', $user);

        return $ticket;
    }
}

class SupportTicketClosedNotificationUserDouble extends User
{
    public $failAfterPersisting = false;
    public $lastNotification = [];
    public $lastNotificationQuery;
    public $notificationsSent = 0;
    public $persistedNotifications = [];

    public function notifications()
    {
        $this->lastNotificationQuery = new SupportTicketClosedNotificationQueryDouble($this);

        return $this->lastNotificationQuery;
    }

    public function notify($content, $routeName = null, $routeParams = null)
    {
        $this->notificationsSent++;
        $this->lastNotification = [$content, $routeName, $routeParams];
        $this->persistedNotifications[] = [
            'description' => $content,
            'route_name' => $routeName,
            'route_params' => $routeParams,
        ];

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }
    }
}

class SupportTicketClosedNotificationQueryDouble
{
    public $filters = [];
    private $user;

    public function __construct(SupportTicketClosedNotificationUserDouble $user)
    {
        $this->user = $user;
    }

    public function where($column, $value)
    {
        $this->filters[] = [$column, $value];

        return $this;
    }

    public function exists()
    {
        foreach ($this->user->persistedNotifications as $notification) {
            $matches = true;

            foreach ($this->filters as $filter) {
                if (!array_key_exists($filter[0], $notification)
                    || $notification[$filter[0]] !== $filter[1]) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
