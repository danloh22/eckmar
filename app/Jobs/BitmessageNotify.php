<?php

namespace App\Jobs;

use App\Marketplace\Bitmessage\Bitmessage;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class BitmessageNotify implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * Bound each transport attempt below the 90-second queue retry window.
     * This prevents a hung RPC call from occupying a worker indefinitely.
     */
    public $timeout = 60;

    public $tries = 2;
    /**
     * Content to send via bitmessage
     *
     * @var string
     */
    private $content;

    /**
     * Message title
     *
     * @var string
     */
    private $title;

    /**
     * Bitmessage address to send content to
     *
     * @var string
     */
    private $address;


    /**
     * Marketplace address
     *
     * @var \Illuminate\Config\Repository|mixed
     */
    private $sender;


    /**
     * Create a new job instance.
     *
     * Keep RPC credentials and the client itself out of the queued payload.
     * Laravel serializes job properties between dispatch and execution.
     *
     * @return void
     */
    public function __construct(string $title,string $content,string $address)
    {
        $this->content = $content;
        $this->address = $address;
        $this->title = $title;
        $this->sender = config('bitmessage.marketplace_address');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() <= $this->tries){
            $bitmessage = new Bitmessage(
                config('bitmessage.connection.username'),
                config('bitmessage.connection.password'),
                config('bitmessage.connection.host'),
                config('bitmessage.connection.port')
            );

            $bitmessage->sendMessage($this->address,$this->sender,$this->title,$this->content);
        } else {
            $this->delete();
        }

    }

    /**
     * Report terminal failure without copying recipient, content, credentials,
     * or an exception message into the application log.
     *
     * @param \Exception $exception
     * @return void
     */
    public function failed(\Exception $exception)
    {
        Log::warning('Bitmessage notification job failed', [
            'exception_class' => get_class($exception),
        ]);
    }
}
