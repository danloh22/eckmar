<?php

namespace Tests\Unit;

use App\User;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class NotificationIdempotencyTest extends TestCase
{
    /**
     * @dataProvider notificationConstraintProvider
     */
    public function testOnlyNotificationPrimaryKeyViolationsAreReplayConflicts(
        $sqlState,
        $driverCode,
        $message,
        $expected
    ) {
        $previous = new \PDOException($message, (int) $sqlState);
        $previous->errorInfo = [$sqlState, $driverCode, $message];
        $exception = new QueryException('insert into notifications', [], $previous);
        $method = new \ReflectionMethod(User::class, 'isNotificationPrimaryKeyConflict');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(new User(), $exception));
    }

    public function notificationConstraintProvider()
    {
        return [
            'mysql notification primary key' => [
                '23000', 1062, "Duplicate entry 'id' for key 'PRIMARY'", true,
            ],
            'mysql qualified notification primary key' => [
                '23000', 1062, "Duplicate entry 'id' for key 'notifications.PRIMARY'", true,
            ],
            'postgres notification primary key' => [
                '23505', 7, 'duplicate key value violates unique constraint "notifications_pkey"', true,
            ],
            'sqlite notification primary key' => [
                '23000', 19, 'UNIQUE constraint failed: notifications.id', true,
            ],
            'mysql unrelated unique key' => [
                '23000', 1062, "Duplicate entry 'value' for key 'notifications_route_name_unique'", false,
            ],
            'postgres unrelated unique key' => [
                '23505', 7, 'duplicate key value violates unique constraint "notifications_route_name_unique"', false,
            ],
            'sqlite unrelated unique key' => [
                '23000', 19, 'UNIQUE constraint failed: notifications.route_name', false,
            ],
            'mysql foreign key' => [
                '23000', 1452, 'Cannot add or update a child row', false,
            ],
        ];
    }
}
