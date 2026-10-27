<?php

namespace Tests\Unit;

use App\Jobs\BitmessageNotify;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
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

    public function testQueuedJobBoundsAttemptsAndRuntimeBelowQueueRetryWindows()
    {
        $job = new BitmessageNotify(
            'Notification from marketplace',
            'A purchase was created',
            'BM-recipient'
        );

        $this->assertSame(2, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(30, $job->retryDelay);

        foreach (config('queue.connections') as $name => $connection) {
            if (!isset($connection['retry_after'])) {
                continue;
            }

            $this->assertGreaterThan(
                $job->timeout,
                $connection['retry_after'],
                $name.' retry_after must exceed the Bitmessage job timeout'
            );
            $this->assertLessThan(
                $connection['retry_after'],
                $job->retryDelay,
                $name.' retry delay must remain below retry_after'
            );
        }
    }

    public function testFirstTransportFailureIsReleasedWithJobSpecificBackoff()
    {
        $job = new FailingBitmessageNotify(
            'Notification from marketplace',
            'A purchase was created',
            'BM-recipient'
        );
        $job->testAttempts = 1;

        $job->handle();

        $this->assertSame(30, $job->releasedWith);
    }

    public function testFinalTransportFailureIsRethrownWithoutRelease()
    {
        $job = new FailingBitmessageNotify(
            'Notification from marketplace',
            'A purchase was created',
            'BM-recipient'
        );
        $job->testAttempts = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('synthetic transport failure');
        $job->handle();
    }

    public function testTerminalFailureAuditExcludesPrivateDeliveryDataAndExceptionMessage()
    {
        $job = new BitmessageNotify(
            'private-title-marker',
            'private-content-marker',
            'BM-private-recipient-marker'
        );

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Bitmessage notification job failed',
                Mockery::on(function ($context) {
                    $encoded = json_encode($context);

                    return isset($context['exception_class'])
                        && $context['exception_class'] === RuntimeException::class
                        && strpos($encoded, 'private-title-marker') === false
                        && strpos($encoded, 'private-content-marker') === false
                        && strpos($encoded, 'BM-private-recipient-marker') === false
                        && strpos($encoded, 'rpc-password-secret-marker') === false;
                })
            );

        $job->failed(new RuntimeException('rpc-password-secret-marker'));
    }
}

class FailingBitmessageNotify extends BitmessageNotify
{
    public $testAttempts = 1;

    public $releasedWith;

    public function attempts()
    {
        return $this->testAttempts;
    }

    public function release($delay = 0)
    {
        $this->releasedWith = $delay;
    }

    protected function sendNotification()
    {
        throw new RuntimeException('synthetic transport failure');
    }
}
