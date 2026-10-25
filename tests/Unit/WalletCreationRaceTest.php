<?php

namespace Tests\Unit;

use App\Services\WalletLedgerService;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class WalletCreationRaceTest extends TestCase
{
    /**
     * @dataProvider databaseErrorProvider
     */
    public function testOnlyUniqueConstraintErrorsAreRecoverable($sqlState, $driverCode, $message, $expected)
    {
        $previous = new \PDOException($message, (int) $sqlState);
        $previous->errorInfo = [$sqlState, $driverCode, $message];
        $exception = new QueryException('insert into wallets', [], $previous);

        $method = new \ReflectionMethod(WalletLedgerService::class, 'isUniqueConstraintViolation');
        $method->setAccessible(true);

        $this->assertSame(
            $expected,
            $method->invoke(new WalletLedgerService(), $exception)
        );
    }

    public function databaseErrorProvider()
    {
        return [
            'mysql duplicate' => ['23000', 1062, 'Duplicate entry for key wallets_user_id_coin_unique', true],
            'postgres duplicate' => ['23505', 7, 'duplicate key value violates unique constraint', true],
            'sqlite duplicate' => ['23000', 19, 'UNIQUE constraint failed: wallets.user_id, wallets.coin', true],
            'mysql foreign key' => ['23000', 1452, 'Cannot add or update a child row', false],
            'mysql deadlock' => ['40001', 1213, 'Deadlock found when trying to get lock', false],
        ];
    }
}
