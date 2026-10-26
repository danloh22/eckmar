<?php


namespace App\Traits;


use App\Jobs\BitmessageNotify;
use App\Notification;
use Illuminate\Database\QueryException;
use Ramsey\Uuid\Uuid;

trait Notifiable
{

    /**
     * Create new notifications for specified user
     *
     * @param string $content
     */
    public function notify(string $content,string $routeName = null,string $routePramas = null) {
        $this->notifications()->create(['description' => $content,'route_name'=>$routeName,'route_params'=> $routePramas]);

        $this->dispatchBitmessageNotification($content);
    }

    /**
     * Persist one notification for a stable delivery identity.
     *
     * The deterministic primary key turns the existing notifications primary
     * key into a concurrency-safe delivery key without changing the schema.
     * A racing replay may only be suppressed after the winning row is read
     * back and its complete identity is verified.
     *
     * @return bool true when this call created the notification
     */
    public function notifyOnce(string $deliveryKey, string $content, string $routeName = null, string $routeParams = null): bool
    {
        $notificationId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'eckmar:notification:' . (string) $this->getKey() . ':' . $deliveryKey
        )->toString();

        $notification = new Notification([
            'description' => $content,
            'route_name' => $routeName,
            'route_params' => $routeParams,
        ]);
        $notification->id = $notificationId;

        try {
            $this->notifications()->save($notification);
        } catch (QueryException $exception) {
            if (!$this->isNotificationPrimaryKeyConflict($exception)) {
                throw $exception;
            }

            $delivered = Notification::find($notificationId);
            if ($delivered === null
                || (string) $delivered->user_id !== (string) $this->getKey()
                || (string) $delivered->description !== $content
                || (string) $delivered->route_name !== (string) $routeName
                || (string) $delivered->route_params !== (string) $routeParams
            ) {
                throw $exception;
            }

            return false;
        }

        $this->dispatchBitmessageNotification($content);

        return true;
    }

    private function isNotificationPrimaryKeyConflict(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = isset($exception->errorInfo[1])
            ? (string) $exception->errorInfo[1]
            : '';
        $message = strtolower($exception->getMessage());

        return ($sqlState === '23505'
                && strpos($message, 'notifications_pkey') !== false)
            || ($sqlState === '23000'
                && $driverCode === '1062'
                && (strpos($message, "for key 'primary'") !== false
                    || strpos($message, "for key 'notifications.primary'") !== false))
            || ($sqlState === '23000'
                && $driverCode === '19'
                && strpos($message, 'unique constraint failed: notifications.id') !== false);
    }

    private function dispatchBitmessageNotification(string $content)
    {

        /**
         * Bitmessage
         */
        if (config('bitmessage.enabled')){
            if ($this->bitmessage_address !== NULL){
                // if its enabled send message
                BitmessageNotify::dispatch('Notification from marketplace',$content,$this->bitmessage_address)->delay(now()->addSecond(1));;
            }
        }
    }

    /**
     * Return user's unread notifications
     *
     * @return mixed
     */
    public function unreadNotifications(){
        return $this->notifications()->where('read',0);
    }
}
