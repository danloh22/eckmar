<?php

namespace Tests\Feature;

use App\Events\Support\TicketClosed;
use App\Http\Controllers\Admin\AdminController;
use App\Ticket;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminTicketAuthorizationTest extends TestCase
{
    private function signInStaff($admin, array $permissions)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'staff-test';
        $user->shouldReceive('isAdmin')->andReturn($admin);
        $user->shouldReceive('hasPermission')->andReturnUsing(function ($permission) use ($permissions) {
            return in_array($permission, $permissions, true);
        });
        $this->actingAs($user);
    }

    public function unauthorizedTicketActions()
    {
        return [['tickets'], ['viewTicket'], ['solveTicket'], ['removeTickets']];
    }

    /** @dataProvider unauthorizedTicketActions */
    public function testUnrelatedModeratorCannotReadOrMutateTickets($action)
    {
        $this->signInStaff(false, ['categories']);
        Event::fake([TicketClosed::class]);
        $ticket = Mockery::mock(Ticket::class)->makePartial();
        $ticket->solved = false;
        $ticket->shouldNotReceive('save');
        $ticket->shouldNotReceive('delete');
        $ticket->shouldNotReceive('replies');
        $controller = new AdminController();

        try {
            if ($action === 'tickets') {
                $controller->tickets();
            } elseif ($action === 'removeTickets') {
                $controller->removeTickets(Request::create('/admin/tickets/remove', 'POST', ['type' => 'all']));
            } else {
                $controller->$action($ticket);
            }
            $this->fail('Ticket access must be denied before reading or changing records.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse($ticket->solved);
        Event::assertNotDispatched(TicketClosed::class);
    }

    public function authorizedStaff()
    {
        return [[false, ['tickets']], [true, []]];
    }

    /** @dataProvider authorizedStaff */
    public function testTicketModeratorAndAdministratorCanStillSolveTickets($admin, $permissions)
    {
        $this->signInStaff($admin, $permissions);
        Event::fake([TicketClosed::class]);
        $ticket = Mockery::mock(Ticket::class)->makePartial();
        $ticket->solved = false;
        $ticket->shouldReceive('save')->once()->andReturn(true);

        $response = (new AdminController())->solveTicket($ticket);

        $this->assertTrue($ticket->solved);
        $this->assertSame(302, $response->getStatusCode());
        Event::assertDispatched(TicketClosed::class, function ($event) use ($ticket) {
            return $event->ticket === $ticket;
        });
    }
}
