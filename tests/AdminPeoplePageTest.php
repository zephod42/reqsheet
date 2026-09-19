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
        $organisationOne = $accounts->createOrganisationAdmin('One', 'One Admin', 'OAD', 'teacher', 'one-pass', 'one-pass', 'one');
        $organisationTwo = $accounts->createOrganisationAdmin('Two', 'Two Admin', 'TAD', 'teacher', 'two-pass', 'two-pass', 'two');
        $firstTeacher = $accounts->createPerson($organisationOne, 'First Teacher', 'FTH', 'first@example.test', ['teacher']);
        $secondTeacher = $accounts->createPerson($organisationTwo, 'Other Teacher', 'OTH', null, ['teacher']);
        assertSameValue(2, $store->accounts['First Teacher']['teacher_number'], 'Teacher number was not scoped and allocated within the organisation.');
        assertSameValue(2, $store->accounts['Other Teacher']['teacher_number'], 'Teacher number did not preserve the organisation’s existing teacher sequence.');
        assertSameValue($organisationOne, $store->accounts['First Teacher']['organisation_id'], 'Created person was assigned to the wrong organisation.');
        assertSameValue($organisationTwo, $store->accounts['Other Teacher']['organisation_id'], 'Second created person was assigned to the wrong organisation.');

        SessionAuth::login(['id' => $store->accounts['One Admin']['id'], 'organisation_id' => $organisationOne, 'display_name' => 'One Admin', 'staff_identifier' => 'OA', 'operational_role' => 'teacher', 'is_admin' => true, 'roles' => ['teacher', 'administrator']]);
        $token = CsrfToken::value();
        $page = new AdminPeoplePage($accounts, $organisationOne, ['id' => $store->accounts['One Admin']['id'], 'organisation_id' => $organisationOne, 'operational_role' => 'teacher', 'is_admin' => true, 'roles' => ['teacher', 'administrator']]);
        $view = $page->handle('GET', []);
        assertContainsValue('First Teacher', $view, 'People page did not list organisation people.');
        assertNotContainsValue('Other Teacher', $view, 'People page leaked another organisation.');
        assertNotContainsValue('Teacher no.', $view, 'People table exposed the internal teacher number column.');
        assertContainsValue('Add Person', $view, 'People page did not move creation behind an Add Person control.');
        assertContainsValue('name="roles[]" value="administrator"', $view, 'People dialog did not expose the administrator role checkbox.');

        $createdView = $page->handle('POST', ['csrf_token' => $token, 'action' => 'add', 'display_name' => 'Combined Person', 'staff_identifier' => 'CPX', 'email' => 'cp@example.test', 'roles' => ['teacher', 'technician', 'administrator']]);
        assertContainsValue('Person created.', $createdView, 'Add Person workflow did not create a person.');
        assertContainsValue('Please use three capital letters.', $createdView, 'Staff-code validation guidance was not rendered.');
        assertNotContainsValue('Teacher number:', $createdView, 'People UI exposed the internal teacher number.');
        assertSameValue(['teacher', 'technician', 'administrator'], $store->accounts['Combined Person']['roles'], 'Multiple roles were not stored cumulatively.');
        assertSameValue(true, SessionAuth::hasRole(['roles' => ['teacher', 'administrator']], 'teacher'), 'Teacher role was not cumulative.');
        assertSameValue(true, SessionAuth::isAdmin(['roles' => ['teacher', 'administrator'], 'is_admin' => true]), 'Administrator role was not cumulative.');

        $editedView = $page->handle('POST', ['csrf_token' => $token, 'action' => 'edit', 'person_id' => $firstTeacher, 'display_name' => 'First Technician', 'staff_identifier' => 'FXX', 'email' => '', 'roles' => ['technician']]);
        assertContainsValue('Person updated.', $editedView, 'Edit Person workflow did not update the person.');
        assertSameValue(['technician'], $store->accounts['First Technician']['roles'], 'Edit Person did not replace the selected role set.');
        $accounts->updatePerson($organisationOne, $store->accounts['Combined Person']['id'], 'Combined Person', 'CPX', 'cp@example.test', ['teacher']);

        self::expectValidation(static fn () => $accounts->updatePerson($organisationOne, $store->accounts['Two Admin']['id'], 'Cross Tenant', 'CTX', null, ['teacher']), 'Cross-tenant person edit was accepted.');
        self::expectValidation(static fn () => $accounts->updatePerson($organisationOne, $store->accounts['One Admin']['id'], 'One Admin', 'OAD', null, ['teacher']), 'The last administrator was removable.');
        SessionAuth::logout();
    }

    private static function expectValidation(callable $operation, string $message): void
    {
        try { $operation(); } catch (AccountValidationException) { return; }
        throw new \RuntimeException($message);
    }
}
