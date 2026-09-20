<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Http\AdminPeoplePage;
use Reqsheet\Http\CsrfToken;
use Reqsheet\Http\SessionAuth;

final class AdminPeoplePageTest
{
    public static function run(): void
    {
        $store = new AccountStoreFake();
        $accounts = new AccountService($store);
        $organisationOne = $accounts->createOrganisationAdmin('One', 'OAD', 'teacher', 'one-pass', 'one-pass', 'one');
        $organisationTwo = $accounts->createOrganisationAdmin('Two', 'TAD', 'teacher', 'two-pass', 'two-pass', 'two');
        $firstTeacher = $accounts->createPerson($organisationOne, 'FTH', ['teacher']);
        $secondTeacher = $accounts->createPerson($organisationTwo, 'OTH', ['teacher']);
        assertSameValue(2, $store->accounts['FTH']['teacher_number'], 'Teacher number was not scoped and allocated within the organisation.');
        assertSameValue(2, $store->accounts['OTH']['teacher_number'], 'Teacher number did not preserve the organisation’s existing teacher sequence.');
        assertSameValue($organisationOne, $store->accounts['FTH']['organisation_id'], 'Created person was assigned to the wrong organisation.');
        assertSameValue($organisationTwo, $store->accounts['OTH']['organisation_id'], 'Second created person was assigned to the wrong organisation.');

        SessionAuth::login(['id' => $store->accounts['OAD']['id'], 'organisation_id' => $organisationOne, 'staff_identifier' => 'OA', 'operational_role' => 'teacher', 'is_admin' => true, 'roles' => ['teacher', 'administrator']]);
        $token = CsrfToken::value();
        $page = new AdminPeoplePage($accounts, $organisationOne, ['id' => $store->accounts['OAD']['id'], 'organisation_id' => $organisationOne, 'operational_role' => 'teacher', 'is_admin' => true, 'roles' => ['teacher', 'administrator']]);
        $view = $page->handle('GET', []);
        assertContainsValue('FTH', $view, 'People page did not list organisation people.');
        assertNotContainsValue('OTH', $view, 'People page leaked another organisation.');
        assertNotContainsValue('Teacher no.', $view, 'People table exposed the internal teacher number column.');
        assertContainsValue('Add Person', $view, 'People page did not move creation behind an Add Person control.');
        assertNotContainsValue('Email', $view, 'People still displayed or collected staff email addresses.');
        assertNotContainsValue('name="display_name"', $view, 'People still collected staff names.');
        assertContainsValue('Delete User', $view, 'People did not offer deletion.');
        assertContainsValue('WARNING: You are deleting the last administrator account.', $view, 'Last-administrator deletion warning was missing.');
        assertContainsValue('name="roles[]" value="administrator"', $view, 'People dialog did not expose the administrator role checkbox.');

        $createdView = $page->handle('POST', ['csrf_token' => $token, 'action' => 'add', 'staff_identifier' => 'CPX', 'roles' => ['teacher', 'technician']]);
        assertContainsValue('Person created.', $createdView, 'Add Person workflow did not create a person.');
        assertContainsValue('Please use three capital letters.', $createdView, 'Staff-code validation guidance was not rendered.');
        assertContainsValue('pattern="[A-Z]{3}"', $createdView, 'Staff-code HTML validation did not require capitals.');
        assertNotContainsValue('Teacher number:', $createdView, 'People UI exposed the internal teacher number.');
        assertSameValue(['teacher', 'technician'], $store->accounts['CPX']['roles'], 'Multiple operational roles were not stored cumulatively.');
        $accounts->claimFirstLogin('CPX', 'combined-pass', 'combined-pass', $organisationOne);
        $promotedView = $page->handle('POST', ['csrf_token' => $token, 'action' => 'edit', 'person_id' => $store->accounts['CPX']['id'], 'staff_identifier' => 'CPX', 'roles' => ['teacher', 'technician', 'administrator']]);
        assertContainsValue('Person updated.', $promotedView, 'A claimed staff account could not receive Administrator access.');
        assertSameValue(['teacher', 'technician', 'administrator'], $store->accounts['CPX']['roles'], 'Administrator role was not stored cumulatively after password setup.');
        assertSameValue(true, SessionAuth::hasRole(['roles' => ['teacher', 'administrator']], 'teacher'), 'Teacher role was not cumulative.');
        assertSameValue(true, SessionAuth::isAdmin(['roles' => ['teacher', 'administrator'], 'is_admin' => true]), 'Administrator role was not cumulative.');

        $editedView = $page->handle('POST', ['csrf_token' => $token, 'action' => 'edit', 'person_id' => $firstTeacher, 'staff_identifier' => 'FXX', 'roles' => ['technician']]);
        assertContainsValue('Person updated.', $editedView, 'Edit Person workflow did not update the person.');
        assertSameValue(['technician'], $store->accounts['FXX']['roles'], 'Edit Person did not replace the selected role set.');
        $accounts->updatePerson($organisationOne, $store->accounts['CPX']['id'], 'CPX', ['teacher']);

        self::expectValidation(static fn () => $accounts->updatePerson($organisationOne, $store->accounts['TAD']['id'], 'CTX', ['teacher']), 'Cross-tenant person edit was accepted.');
        self::expectValidation(static fn () => $accounts->updatePerson($organisationOne, $store->accounts['OAD']['id'], 'OAD', ['teacher']), 'The last administrator was removable.');
        $deletedView = $page->handle('POST', ['csrf_token' => $token, 'action' => 'delete', 'person_id' => $firstTeacher, 'confirm_delete' => 'yes']);
        assertContainsValue('User deleted.', $deletedView, 'Administrator could not delete ordinary staff.');
        assertSameValue(false, $store->findUserById($firstTeacher)['is_active'], 'Deleted user retained login access.');
        assertSameValue(2, $store->findUserById($firstTeacher)['auth_version'], 'Deletion did not revoke account sessions.');
        assertNotContainsValue('>FXX<', $deletedView, 'Deleted staff remained in the normal People list.');
        self::expectValidation(static fn () => $accounts->deletePerson(1, $organisationOne, $secondTeacher, true, true), 'Cross-tenant deletion succeeded.');
        $csrfFailure = $page->handle('POST', ['csrf_token' => 'wrong', 'action' => 'delete', 'person_id' => 1, 'confirm_delete' => 'yes', 'confirm_last_admin' => 'yes']);
        assertContainsValue('The form expired.', $csrfFailure, 'Deletion did not enforce CSRF.');
        self::expectValidation(static fn () => $accounts->deletePerson(1, $organisationOne, 1, true, false), 'Last-admin deletion bypassed explicit warning confirmation.');
        $selfDelete = $page->handle('POST', ['csrf_token' => $token, 'action' => 'delete', 'person_id' => 1, 'confirm_delete' => 'yes', 'confirm_last_admin' => 'yes']);
        assertSameValue('', $selfDelete, 'Self-deletion did not redirect to login.');
        assertSameValue(null, SessionAuth::current(), 'Self-deletion retained the authenticated session.');
        assertSameValue(0, $store->activeAdministratorCount($organisationOne), 'The last administrator could not be deleted.');
        self::expectValidation(static fn () => $accounts->authenticate('OAD', 'one-pass', $organisationOne), 'Deleted administrator could still authenticate.');
        SessionAuth::logout();
    }

    private static function expectValidation(callable $operation, string $message): void
    {
        try { $operation(); } catch (AccountValidationException) { return; }
        throw new \RuntimeException($message);
    }
}
