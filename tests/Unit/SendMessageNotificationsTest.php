<?php

namespace Tests\Unit;

use App\Events\Message\MessageSent;
use App\Listeners\SendMessageNotifications;
use App\Message;
use App\User;
use Tests\TestCase;

class SendMessageNotificationsTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendMessageNotificationTwice()
    {
        $message = $this->messageWithNotificationReceiver($receiver, 'message-id');
        $event = new MessageSent($message);
        $listener = new SendMessageNotifications();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $receiver->notificationsSent);
        $this->assertSame('message-received:message-id', $receiver->lastDeliveryKey);
        $this->assertSame([
            'You have received new message from [sender]',
            'profile.messages',
            serialize(['conversation' => 'conversation-id']),
        ], $receiver->lastNotification);
    }

    public function testDifferentMessagesInSameConversationUseDifferentDeliveryIdentities()
    {
        $listener = new SendMessageNotifications();
        $first = $this->messageWithNotificationReceiver($receiver, 'first-message-id');
        $second = $this->messageWithExistingReceiver($receiver, 'second-message-id');

        $listener->handle(new MessageSent($first));
        $listener->handle(new MessageSent($second));

        $this->assertSame(2, $receiver->notificationsSent);
        $this->assertSame([
            'message-received:first-message-id',
            'message-received:second-message-id',
        ], array_keys($receiver->persistedNotifications));
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateMessageNotification()
    {
        $message = $this->messageWithNotificationReceiver($receiver, 'message-id');
        $event = new MessageSent($message);
        $listener = new SendMessageNotifications();
        $receiver->failAfterPersisting = true;

        try {
            $listener->handle($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $receiver->failAfterPersisting = false;
        $listener->handle($event);

        $this->assertSame(1, $receiver->notificationsSent);
        $this->assertSame(['message-received:message-id'], array_keys($receiver->persistedNotifications));
    }

    private function messageWithNotificationReceiver(&$receiver, $messageId)
    {
        $receiver = new MessageNotificationUserDouble();

        return $this->messageWithExistingReceiver($receiver, $messageId);
    }

    private function messageWithExistingReceiver($receiver, $messageId)
    {
        $sender = new User();
        $sender->username = 'sender';

        return new MessageNotificationMessageDouble(
            $messageId,
            $sender,
            $receiver,
            'conversation-id'
        );
    }
}

class MessageNotificationMessageDouble extends Message
{
    private $senderDouble;
    private $receiverDouble;
    private $conversationId;

    public function __construct($messageId, User $sender, User $receiver, $conversationId)
    {
        parent::__construct();
        $this->id = $messageId;
        $this->senderDouble = $sender;
        $this->receiverDouble = $receiver;
        $this->conversationId = $conversationId;
    }

    public function getSender(): User
    {
        return $this->senderDouble;
    }

    public function getReceiver(): User
    {
        return $this->receiverDouble;
    }

    public function conversation()
    {
        $conversationId = $this->conversationId;

        return new class($conversationId) {
            private $conversationId;

            public function __construct($conversationId)
            {
                $this->conversationId = $conversationId;
            }

            public function first()
            {
                return (object) ['id' => $this->conversationId];
            }
        };
    }
}

class MessageNotificationUserDouble extends User
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
