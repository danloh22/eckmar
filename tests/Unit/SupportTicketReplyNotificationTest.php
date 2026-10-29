<?php

namespace Tests\Unit;

use App\Events\Support\NewTicketReply;
use App\Listeners\SupportEventSubscriber;
use App\Ticket;
use App\TicketReply;
use App\User;
use Tests\TestCase;

class SupportTicketReplyNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendTicketReplyNotificationTwice()
    {
        $reply = $this->replyWithNotificationUser($user, 'reply-id');
        $event = new NewTicketReply($reply);
        $subscriber = new SupportEventSubscriber();

        $subscriber->onNewTicketMessage($event);
        $subscriber->onNewTicketMessage($event);

        $this->assertSame(1, $user->notificationsSent);
        $this->assertSame('ticket-reply:reply-id', $user->lastDeliveryKey);
        $this->assertSame([
            'There is a new reply on your support ticket by [support-agent]',
            'profile.tickets',
            serialize(['ticket' => 'ticket-id']),
        ], $user->lastNotification);
    }

    public function testDifferentRepliesOnSameTicketUseDifferentDeliveryIdentities()
    {
        $subscriber = new SupportEventSubscriber();
        $firstReply = $this->replyWithNotificationUser($user, 'first-reply-id');
        $secondReply = $this->replyWithExistingNotificationUser($user, 'second-reply-id');

        $subscriber->onNewTicketMessage(new NewTicketReply($firstReply));
        $subscriber->onNewTicketMessage(new NewTicketReply($secondReply));

        $this->assertSame(2, $user->notificationsSent);
        $this->assertSame([
            'ticket-reply:first-reply-id',
            'ticket-reply:second-reply-id',
        ], array_keys($user->persistedNotifications));
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateTicketReplyNotification()
    {
        $reply = $this->replyWithNotificationUser($user, 'reply-id');
        $event = new NewTicketReply($reply);
        $subscriber = new SupportEventSubscriber();
        $user->failAfterPersisting = true;

        try {
            $subscriber->onNewTicketMessage($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $user->failAfterPersisting = false;
        $subscriber->onNewTicketMessage($event);

        $this->assertSame(1, $user->notificationsSent);
        $this->assertSame(['ticket-reply:reply-id'], array_keys($user->persistedNotifications));
    }

    private function replyWithNotificationUser(&$user, $replyId)
    {
        $user = new SupportTicketReplyNotificationUserDouble();

        return $this->replyWithExistingNotificationUser($user, $replyId);
    }

    private function replyWithExistingNotificationUser($user, $replyId)
    {
        $sender = new User();
        $sender->username = 'support-agent';

        $ticket = new Ticket();
        $ticket->id = 'ticket-id';
        $ticket->setRelation('user', $user);

        $reply = new TicketReply();
        $reply->id = $replyId;
        $reply->setRelation('user', $sender);
        $reply->setRelation('ticket', $ticket);

        return $reply;
    }
}

class SupportTicketReplyNotificationUserDouble extends User
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
