<?php

namespace Tests\Unit;

use App\Traits\Experience;
use PHPUnit\Framework\TestCase;

class ExperienceAtomicUpdateTest extends TestCase
{
    public function testGrantDelegatesToAtomicIncrementAndRefreshes()
    {
        $subject = $this->subject();

        $subject->grantExperience(7);

        $this->assertSame([['experience', 7]], $subject->increments);
        $this->assertSame([], $subject->decrements);
        $this->assertSame(1, $subject->refreshes);
    }

    public function testTakeDelegatesToAtomicDecrementAndRefreshes()
    {
        $subject = $this->subject();

        $subject->takeExperience(3);

        $this->assertSame([], $subject->increments);
        $this->assertSame([['experience', 3]], $subject->decrements);
        $this->assertSame(1, $subject->refreshes);
    }

    public function testFailedAtomicUpdateDoesNotRefreshStaleState()
    {
        $subject = $this->subject();
        $subject->affected = 0;

        try {
            $subject->grantExperience(1);
            $this->fail('A missing experience row must fail closed.');
        } catch (\RuntimeException $expected) {
            $this->assertSame('Unable to persist experience grant.', $expected->getMessage());
        }

        $this->assertSame(0, $subject->refreshes);
    }

    private function subject()
    {
        return new class {
            use Experience;

            public $affected = 1;
            public $increments = [];
            public $decrements = [];
            public $refreshes = 0;

            public function increment($column, $amount = 1, array $extra = [])
            {
                $this->increments[] = [$column, $amount];

                return $this->affected;
            }

            public function decrement($column, $amount = 1, array $extra = [])
            {
                $this->decrements[] = [$column, $amount];

                return $this->affected;
            }

            public function refresh()
            {
                $this->refreshes++;

                return $this;
            }
        };
    }
}
