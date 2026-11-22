<?php

namespace APP\plugins\generic\dossierManager\tests\unit;

use APP\plugins\generic\dossierManager\classes\ReminderPolicy as P;
use PHPUnit\Framework\TestCase;

class ReminderPolicyTest extends TestCase
{
    private function c(array $values = []): array
    {
        return $values + ['deadline' => '2026-03-31', 'dateInvited' => '2026-01-10 12:00:00', 'reminderSent' => null, 'overdueCount' => 0, 'lastOverdue' => null];
    }

    public function testBeforeReminderInTheWindowOnce(): void
    {
        $this->assertNull(P::due($this->c(), '2026-03-23', 7, 7, 4));
        $this->assertSame(P::BEFORE, P::due($this->c(), '2026-03-24', 7, 7, 4));
        $this->assertSame(P::BEFORE, P::due($this->c(), '2026-03-31', 7, 7, 4));
        $this->assertNull(P::due($this->c(['reminderSent' => '2026-03-24 03:00:00']), '2026-03-25', 7, 7, 4));
    }

    public function testNoBeforeReminderRightAfterTheInvitation(): void
    {
        $this->assertNull(P::due($this->c(['dateInvited' => '2026-03-29 10:00:00']), '2026-03-30', 7, 7, 4));
        $this->assertSame(P::BEFORE, P::due($this->c(['dateInvited' => '2026-03-28 10:00:00']), '2026-03-30', 7, 7, 4));
    }

    public function testZeroDaysDisablesTheBeforeReminder(): void
    {
        $this->assertNull(P::due($this->c(), '2026-03-30', 0, 7, 4));
    }

    public function testOverdueTheDayAfterTheDeadlineThenEveryInterval(): void
    {
        $this->assertSame(P::OVERDUE, P::due($this->c(), '2026-04-01', 7, 7, 4));
        $sent = $this->c(['overdueCount' => 1, 'lastOverdue' => '2026-04-01 03:00:00']);
        $this->assertNull(P::due($sent, '2026-04-07', 7, 7, 4));
        $this->assertSame(P::OVERDUE, P::due($sent, '2026-04-08', 7, 7, 4));
    }

    public function testOverdueStopsAtTheMaximumUnlessUnlimited(): void
    {
        $sent = $this->c(['overdueCount' => 4, 'lastOverdue' => '2026-04-22 03:00:00']);
        $this->assertNull(P::due($sent, '2026-05-30', 7, 7, 4));
        $this->assertSame(P::OVERDUE, P::due($sent, '2026-05-30', 7, 7, 0));
    }

    public function testNoDeadlineOrNoInvitationNoReminder(): void
    {
        $this->assertNull(P::due($this->c(['deadline' => null]), '2026-04-01', 7, 7, 4));
        $this->assertNull(P::due($this->c(['dateInvited' => null]), '2026-04-01', 7, 7, 4));
    }
}
