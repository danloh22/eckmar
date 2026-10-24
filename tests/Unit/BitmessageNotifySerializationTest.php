<?php

namespace Tests\Unit;

use App\Jobs\BitmessageNotify;
use Tests\TestCase;

class BitmessageNotifySerializationTest extends TestCase
{
    public function testQueuedPayloadDoesNotContainRpcCredentials()
    {
        config([
            'bitmessage.marketplace_address' => 'BM-marketplace-sender',
            'bitmessage.connection.username' => 'queue-rpc-user-secret',
            'bitmessage.connection.password' => 'queue-rpc-password-secret',
            'bitmessage.connection.host' => '127.0.0.1',
            'bitmessage.connection.port' => 8442,
        ]);

        $payload = serialize(new BitmessageNotify(
            'Notification from marketplace',
            'A purchase was created',
            'BM-recipient'
        ));

        $this->assertFalse(strpos($payload, 'queue-rpc-user-secret') !== false);
        $this->assertFalse(strpos($payload, 'queue-rpc-password-secret') !== false);
        $this->assertFalse(strpos($payload, 'PhpXmlRpc') !== false);
        $this->assertFalse(strpos($payload, 'Bitmessage\\Bitmessage') !== false);
    }

    public function testQueuedPayloadRetainsDeliveryData()
    {
        config([
            'bitmessage.marketplace_address' => 'BM-marketplace-sender',
            'bitmessage.connection.username' => 'queue-rpc-user-secret',
            'bitmessage.connection.password' => 'queue-rpc-password-secret',
            'bitmessage.connection.host' => '127.0.0.1',
            'bitmessage.connection.port' => 8442,
        ]);

        $payload = serialize(new BitmessageNotify(
            'Notification from marketplace',
            'A purchase was created',
            'BM-recipient'
        ));

        $this->assertTrue(strpos($payload, 'Notification from marketplace') !== false);
        $this->assertTrue(strpos($payload, 'A purchase was created') !== false);
        $this->assertTrue(strpos($payload, 'BM-recipient') !== false);
        $this->assertTrue(strpos($payload, 'BM-marketplace-sender') !== false);
    }
}
