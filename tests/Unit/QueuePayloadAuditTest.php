<?php

namespace Tests\Unit;

use App\Jobs\BitmessageNotify;
use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class QueuePayloadAuditTest extends TestCase
{
    /**
     * Keep the payload audit in step with every application job and queued
     * listener. Adding a queueable class requires an explicit safe fixture.
     */
    public function testPayloadAuditCoversEveryQueueableApplicationClass()
    {
        $discovered = $this->discoverQueueableApplicationClasses();
        $audited = array_keys($this->auditedQueueableInstances());

        sort($discovered);
        sort($audited);

        $this->assertSame(
            $discovered,
            $audited,
            'Every application ShouldQueue class must have an explicit queue payload audit fixture.'
        );
    }

    /**
     * Queue payloads must not retain connection credentials or RPC/HTTP
     * client objects. The marker values are deliberately unique so a leaked
     * config value is unambiguous.
     */
    public function testAuditedQueuePayloadsExcludeCredentialsAndServiceClients()
    {
        foreach ($this->auditedQueueableInstances() as $class => $queueable) {
            $payload = serialize($queueable);

            foreach ($this->forbiddenPayloadFragments() as $fragment) {
                $this->assertFalse(
                    strpos($payload, $fragment) !== false,
                    $class.' queue payload contains forbidden fragment: '.$fragment
                );
            }
        }
    }

    /**
     * @return array<string, object>
     */
    private function auditedQueueableInstances()
    {
        config([
            'bitmessage.marketplace_address' => 'BM-marketplace-sender',
            'bitmessage.connection.username' => 'queue-audit-rpc-user-secret',
            'bitmessage.connection.password' => 'queue-audit-rpc-password-secret',
            'bitmessage.connection.host' => 'queue-audit-rpc-host.internal',
            'bitmessage.connection.port' => 8442,
        ]);

        return [
            BitmessageNotify::class => new BitmessageNotify(
                'Queue payload audit',
                'No live transport call is made',
                'BM-audit-recipient'
            ),
        ];
    }

    /**
     * @return string[]
     */
    private function forbiddenPayloadFragments()
    {
        return [
            'queue-audit-rpc-user-secret',
            'queue-audit-rpc-password-secret',
            'queue-audit-rpc-host.internal',
            'PhpXmlRpc',
            'GuzzleHttp\\Client',
            'Marketplace\\Bitmessage\\Bitmessage',
            'Marketplace\\Utility\\RPCWrapper',
            'MoneroRPC\\jsonRPCClient',
            'MoneroRPC\\walletRPC',
            'MoneroRPC\\daemonRPC',
        ];
    }

    /**
     * @return string[]
     */
    private function discoverQueueableApplicationClasses()
    {
        $classes = [];

        foreach (['Jobs', 'Listeners'] as $directory) {
            $root = app_path($directory);

            if (!is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = substr($file->getPathname(), strlen(app_path()) + 1);
                $class = 'App\\'.str_replace(
                    ['/', DIRECTORY_SEPARATOR, '.php'],
                    ['\\', '\\', ''],
                    $relativePath
                );

                if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
