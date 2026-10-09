<?php

namespace Tests\Feature;

use App\Conversation;
use App\Http\Controllers\MessageController;
use App\Http\Requests\Profile\NewConversationRequest;
use App\Http\Requests\Profile\NewMessageRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Tests\TestCase;

class MessageSubmissionRoutesTest extends TestCase
{
    public function testBothMessageSubmissionEndpointsRequirePost()
    {
        foreach (['profile.messages.message.new', 'profile.messages.send.message'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame(['POST'], $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('can_read_messages', $route->gatherMiddleware());
        }
    }

    public function testLegacyGetCannotDispatchAMessageWrite()
    {
        $this->expectException(MethodNotAllowedHttpException::class);
        Route::getRoutes()->match(Request::create('/profile/messages/example-conversation/sendmessage?message=private', 'GET'));
    }

    public function testFirstMessageUsesTheSameValidationAndPersistenceContract()
    {
        $request = new NewConversationRequest();
        $this->assertInstanceOf(NewMessageRequest::class, $request);
        $this->assertSame((new NewMessageRequest())->rules()['message'], $request->rules()['message']);
        $this->assertSame('required|exists:users,username', $request->rules()['username']);
    }

    public function testSharedSendPathAuthorizesAndPersistsOnceAndRedirectsWithoutMessage()
    {
        $conversation = Mockery::mock(Conversation::class)->makePartial();
        $conversation->id = 'test-conversation';
        $conversation->shouldReceive('updateTime')->once();
        $request = Mockery::mock(NewConversationRequest::class)->makePartial();
        $request->replace(['username' => 'receiver', 'message' => 'private message text']);
        $request->shouldReceive('persist')->once()->with($conversation);
        $controller = Mockery::mock(MessageController::class)->makePartial();
        $controller->shouldReceive('authorize')->once()->with('update', $conversation);

        $response = $controller->newMessage($request, $conversation);

        $this->assertSame(route('profile.messages', $conversation), $response->getTargetUrl());
        $this->assertNull(parse_url($response->getTargetUrl(), PHP_URL_QUERY));
        $this->assertNotContains('private', $response->getTargetUrl());
    }
}
