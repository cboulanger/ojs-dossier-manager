<?php

use APP\plugins\generic\dossierManager\classes\Dossier;
use APP\plugins\generic\dossierManager\classes\DossierAccess;
use PHPUnit\Framework\TestCase;

class DossierAccessTest extends TestCase
{
    public function testGuestEditorsOnlyReachTheReadOnlyOperations(): void
    {
        foreach (['index', 'view', 'notifyMode'] as $op) {
            $this->assertTrue(DossierAccess::mayCallOp(false, true, $op), $op);
        }
        foreach (['create', 'edit', 'save', 'dossierAction', 'addContribution', 'updateContribution',
                'contributionAction', 'compose', 'sendInvitations', 'users', 'settings', 'saveSettings'] as $op) {
            $this->assertFalse(DossierAccess::mayCallOp(false, true, $op), $op);
        }
    }

    public function testOthersReachNothing(): void
    {
        $this->assertFalse(DossierAccess::mayCallOp(false, false, 'index'));
        $this->assertFalse(DossierAccess::mayCallOp(false, false, 'view'));
    }

    public function testManagersAndDossierEditorsReachEverything(): void
    {
        $this->assertTrue(DossierAccess::mayCallOp(true, false, 'save'));
        $this->assertTrue(DossierAccess::mayCallOp(true, true, 'users'));
    }

    public function testView(): void
    {
        $this->assertTrue(DossierAccess::canView(true, false, false), 'manager or dossier editor');
        $this->assertTrue(DossierAccess::canView(false, false, true), 'guest editor');
        $this->assertTrue(DossierAccess::canView(false, true, false), 'responsible editor');
        $this->assertFalse(DossierAccess::canView(false, false, false), 'guest editor of another dossier');
    }

    public function testOnlyManagersAndResponsibleEditorsEdit(): void
    {
        $this->assertTrue(DossierAccess::canEdit(true, false));
        $this->assertTrue(DossierAccess::canEdit(false, true));
        $this->assertFalse(DossierAccess::canEdit(false, false), 'guest editor or dossier editor not responsible');
    }

    public function testNotifyMode(): void
    {
        $this->assertTrue(DossierAccess::canSetNotifyMode(true, false));
        $this->assertTrue(DossierAccess::canSetNotifyMode(false, true));
        $this->assertFalse(DossierAccess::canSetNotifyMode(false, false));
    }

    public function testSubmissionLinks(): void
    {
        $this->assertFalse(DossierAccess::canOpenSubmissions(false, true));
        $this->assertTrue(DossierAccess::canOpenSubmissions(true, true));
        $this->assertTrue(DossierAccess::canOpenSubmissions(false, false));
    }

    public function testNotifiedEditorsAreResponsibleAndGuestEditors(): void
    {
        $dossier = new Dossier();
        $dossier->editors = [1 => 'default'];
        $dossier->guestEditors = [2 => 'digest'];
        $this->assertSame([1 => 'default', 2 => 'digest'], $dossier->getNotifiedEditors());
        $this->assertTrue($dossier->isEditor(1));
        $this->assertFalse($dossier->isEditor(2));
        $this->assertTrue($dossier->isGuestEditor(2));
        $this->assertFalse($dossier->isGuestEditor(1));
    }
}
