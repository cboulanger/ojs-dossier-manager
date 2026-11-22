<?php

namespace APP\plugins\generic\dossierManager\tests\unit;

use APP\plugins\generic\dossierManager\classes\ContributionStatus as S;
use PHPUnit\Framework\TestCase;

class ContributionStatusTest extends TestCase
{
    public function testWithoutSubmissionTheStoredStatusCounts(): void
    {
        $this->assertSame(S::PLANNED, S::derive('planned', null));
        $this->assertSame(S::INVITED, S::derive('invited', null));
    }

    public function testDeclinedAndDroppedWinOverTheSubmission(): void
    {
        $this->assertSame(S::DECLINED, S::derive('declined', ['progress' => '', 'status' => 1]));
        $this->assertSame(S::DROPPED, S::derive('dropped', ['progress' => 'start', 'status' => 1]));
    }

    public function testSubmissionStates(): void
    {
        $this->assertSame(S::IN_PROGRESS, S::derive('invited', ['progress' => 'files', 'status' => 1]));
        $this->assertSame(S::SUBMITTED, S::derive('invited', ['progress' => '', 'status' => 1]));
        $this->assertSame(S::SUBMITTED, S::derive('invited', ['progress' => '', 'status' => 5]));
        $this->assertSame(S::NOT_ACCEPTED, S::derive('invited', ['progress' => '', 'status' => 4]));
        $this->assertSame(S::PUBLISHED, S::derive('invited', ['progress' => '', 'status' => 3]));
    }

    public function testOpenStatusesAreThoseThatGetReminders(): void
    {
        $this->assertTrue(S::isOpen(S::INVITED));
        $this->assertTrue(S::isOpen(S::IN_PROGRESS));
        $this->assertFalse(S::isOpen(S::PLANNED));
        $this->assertFalse(S::isOpen(S::SUBMITTED));
        $this->assertFalse(S::isOpen(S::DECLINED));
    }
}
