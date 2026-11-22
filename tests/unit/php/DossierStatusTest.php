<?php

namespace APP\plugins\generic\dossierManager\tests\unit;

use APP\plugins\generic\dossierManager\classes\ContributionStatus as C;
use APP\plugins\generic\dossierManager\classes\DossierStatus as D;
use PHPUnit\Framework\TestCase;

class DossierStatusTest extends TestCase
{
    public function testCancelledAndPublishedComeFirst(): void
    {
        $this->assertSame(D::CANCELLED, D::derive(true, true, true, '2026-01-01', [C::INVITED], '2026-02-01'));
        $this->assertSame(D::PUBLISHED, D::derive(false, true, true, '2026-01-01', [C::INVITED], '2026-02-01'));
    }

    public function testPlanningUntilInvited(): void
    {
        $this->assertSame(D::PLANNING, D::derive(false, false, false, null, [C::PLANNED], '2026-02-01'));
    }

    public function testInvitedUntilTheDeadlineThenCollecting(): void
    {
        $this->assertSame(D::INVITED, D::derive(false, true, false, '2026-02-01', [C::INVITED, C::SUBMITTED], '2026-02-01'));
        $this->assertSame(D::COLLECTING, D::derive(false, true, false, '2026-02-01', [C::INVITED, C::SUBMITTED], '2026-02-02'));
    }

    public function testCompleteWhenEveryActiveContributionIsSubmitted(): void
    {
        $this->assertSame(D::COMPLETE, D::derive(false, true, false, '2026-02-01', [C::SUBMITTED, C::DECLINED, C::DROPPED, C::PUBLISHED], '2026-01-01'));
        // Nothing active left: not complete
        $this->assertSame(D::INVITED, D::derive(false, true, false, '2026-02-01', [C::DECLINED], '2026-01-01'));
    }
}
