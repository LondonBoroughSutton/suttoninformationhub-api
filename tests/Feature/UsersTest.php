<?php

namespace Tests\Feature;

use App\Events\EndpointHit;
use App\Models\Audit;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use Tests\TestCase;

class UsersTest extends TestCase
{
    /*
     * ==================================================
     * List all the users.
     * ==================================================
     */

    public function test_guest_cannot_list_them()
    {
        $response = $this->json('GET', '/core/v1/users');

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_can_list_them()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $response = $this->json('GET', '/core/v1/users', ['include' => 'user-roles']);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_audit_created_when_listed()
    {
        $this->fakeEvents();

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $this->json('GET', '/core/v1/users');

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($user) {
            return ($event->getAction() === Audit::ACTION_READ) &&
                ($event->getUser()->id === $user->id);
        });
    }

    public function test_service_worker_can_filter_by_highest_role_name()
    {
        $service = factory(Service::class)->create();
        $serviceAdmin = factory(User::class)->create()->makeServiceAdmin($service);
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $serviceAdminRoleName = Role::serviceAdmin()->name;

        $response = $this->json('GET', "/core/v1/users?filter[highest_role]={$serviceAdminRoleName}");

        $response->assertStatus(Response::HTTP_OK);
        $data = $this->getResponseContent($response);

        $this->assertEquals(1, count($data['data']));
        $this->assertEquals($serviceAdmin->id, $data['data'][0]['id']);
    }

    public function test_service_worker_can_sort_by_highest_role()
    {
        $service = factory(Service::class)->create();
        $serviceAdmin = factory(User::class)->create()->makeServiceAdmin($service);
        $serviceWorker = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($serviceWorker);

        $response = $this->json('GET', '/core/v1/users?sort=-highest_role');

        $response->assertStatus(Response::HTTP_OK);
        $data = $this->getResponseContent($response);

        $this->assertEquals($serviceAdmin->id, $data['data'][1]['id']);
        $this->assertEquals($serviceWorker->id, $data['data'][0]['id']);
    }

    public function test_service_worker_can_sort_by_at_organisation()
    {
        $organisation = factory(Organisation::class)->create();
        $organisationAdmin = factory(User::class)->create()->makeOrganisationAdmin($organisation);
        $user = factory(User::class)->create()->makeServiceWorker(
            factory(Service::class)->create()
        );
        Passport::actingAs($user);

        $response = $this->json('GET', "/core/v1/users?filter[at_organisation]={$organisation->id}");

        $response->assertStatus(Response::HTTP_OK);
        $data = $this->getResponseContent($response);

        $this->assertEquals(1, count($data['data']));
        $this->assertEquals($organisationAdmin->id, $data['data'][0]['id']);
    }

    public function test_service_worker_can_sort_by_at_service()
    {
        $service = factory(Service::class)->create();
        $serviceAdmin = factory(User::class)->create()->makeServiceAdmin($service);
        $user = factory(User::class)->create()->makeServiceWorker(
            factory(Service::class)->create()
        );
        Passport::actingAs($user);

        $response = $this->json('GET', "/core/v1/users?filter[at_service]={$service->id}");

        $response->assertStatus(Response::HTTP_OK);
        $data = $this->getResponseContent($response);

        $this->assertEquals(1, count($data['data']));
        $this->assertEquals($serviceAdmin->id, $data['data'][0]['id']);
    }

    public function test_service_worker_can_sort_by_at_organisation_and_includes_service_workers()
    {
        $service = factory(Service::class)->create();
        $serviceWorker = factory(User::class)->create()->makeServiceWorker($service);
        $user = factory(User::class)->create()->makeServiceWorker(
            factory(Service::class)->create()
        );
        Passport::actingAs($user);

        $response = $this->json('GET', "/core/v1/users?filter[at_organisation]={$service->organisation_id}");

        $response->assertStatus(Response::HTTP_OK);
        $data = $this->getResponseContent($response);

        $this->assertEquals(1, count($data['data']));
        $this->assertEquals($serviceWorker->id, $data['data'][0]['id']);
    }

    public function test_service_worker_can_sort_by_at_organisation_and_excludes_global_admins()
    {
        $organisation = factory(Organisation::class)->create();
        $organisationAdmin = factory(User::class)->create()->makeOrganisationAdmin($organisation);
        // This user shouldn't show up in the results.
        factory(User::class)->create()->makeGlobalAdmin();
        $user = factory(User::class)->create()->makeServiceWorker(
            factory(Service::class)->create()
        );
        Passport::actingAs($user);

        $response = $this->json('GET', "/core/v1/users?filter[at_organisation]={$organisation->id}");

        $response->assertStatus(Response::HTTP_OK);
        $data = $this->getResponseContent($response);

        $this->assertEquals(1, count($data['data']));
        $this->assertEquals($organisationAdmin->id, $data['data'][0]['id']);
    }

    /*
     * ==================================================
     * Create a user.
     * ==================================================
     */

    /*
     * Guest Invoked.
     */
    public function test_guest_cannot_create_one()
    {
        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_SUPER_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    /*
     * Service Worker Invoked.
     */
    public function test_service_worker_cannot_create_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_SUPER_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /*
     * Service Admin Invoked.
     */
    public function test_service_admin_cannot_create_service_worker_for_another_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => factory(Service::class)->create()->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_service_admin_can_create_service_worker_for_their_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertEquals(1, $createdUser->roles()->count());
    }

    public function test_service_admin_cannot_create_service_admin_for_another_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => factory(Service::class)->create()->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_service_admin_can_create_service_admin_for_their_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertEquals(2, $createdUser->roles()->count());
    }

    public function test_service_admin_cannot_create_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /*
     * Organisation Admin Invoked.
     */
    public function test_organisation_admin_cannot_create_service_worker_for_another_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => factory(Service::class)->create()->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_organisation_admin_can_create_service_worker_for_their_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertEquals(1, $createdUser->roles()->count());
    }

    public function test_organisation_admin_cannot_create_service_admin_for_another_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => factory(Service::class)->create()->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_organisation_admin_can_create_service_admin_for_their_service()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertEquals(2, $createdUser->roles()->count());
    }

    public function test_organisation_admin_cannot_create_organisation_admin_for_another_organisation()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => factory(Organisation::class)->create()->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_organisation_admin_can_create_organisation_admin_for_their_organisation()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertTrue($createdUser->isOrganisationAdmin($service->organisation));
        $this->assertEquals(3, $createdUser->roles()->count());
    }

    public function test_organisation_admin_cannot_create_global_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_GLOBAL_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /*
     * Global Admin Invoked.
     */
    public function test_global_admin_can_create_service_worker()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertEquals(1, $createdUser->roles()->count());
    }

    public function test_global_admin_can_create_service_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertEquals(2, $createdUser->roles()->count());
    }

    public function test_global_admin_can_create_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertTrue($createdUser->isOrganisationAdmin($service->organisation));
        $this->assertEquals(3, $createdUser->roles()->count());
    }

    public function test_global_admin_can_create_global_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_GLOBAL_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertTrue($createdUser->isOrganisationAdmin($service->organisation));
        $this->assertTrue($createdUser->isGlobalAdmin());
        $this->assertEquals(4, $createdUser->roles()->count());
    }

    public function test_global_admin_cannot_create_super_admin()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_SUPER_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /*
     * Super Admin Invoked.
     */

    public function test_super_admin_can_create_service_worker()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertEquals(1, $createdUser->roles()->count());
    }

    public function test_super_admin_can_create_service_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertEquals(2, $createdUser->roles()->count());
    }

    public function test_super_admin_can_create_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertTrue($createdUser->isOrganisationAdmin($service->organisation));
        $this->assertEquals(3, $createdUser->roles()->count());
    }

    public function test_super_admin_can_create_global_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_GLOBAL_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertTrue($createdUser->isOrganisationAdmin($service->organisation));
        $this->assertTrue($createdUser->isGlobalAdmin());
        $this->assertEquals(4, $createdUser->roles()->count());
    }

    public function test_super_admin_can_create_super_admin()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_SUPER_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_CREATED);
        $createdUserId = json_decode($response->getContent(), true)['data']['id'];
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->isServiceWorker($service));
        $this->assertTrue($createdUser->isServiceAdmin($service));
        $this->assertTrue($createdUser->isOrganisationAdmin($service->organisation));
        $this->assertTrue($createdUser->isGlobalAdmin());
        $this->assertTrue($createdUser->isSuperAdmin());
        $this->assertEquals(5, $createdUser->roles()->count());
    }

    public function test_super_admin_can_create_super_admin_with_soft_deleted_users_email()
    {
        $user = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($user);

        $deletedUser = factory(User::class)->create(['email' => 'test@example.com'])->makeSuperAdmin();
        $deletedUser->delete();

        $response = $this->json('POST', '/core/v1/users', [
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'email' => 'test@example.com',
            'phone' => random_uk_phone(),
            'password' => 'Pa$$w0rd',
            'roles' => [
                ['role' => Role::NAME_SUPER_ADMIN],
            ],
        ]);

        $response->assertStatus(Response::HTTP_CREATED);
        $this->assertDatabaseHas(table(User::class), [
            'id' => $deletedUser->id,
            'email' => 'test@example.com',
        ]);
        $this->assertDatabaseMissing(table(User::class), [
            'id' => $deletedUser->id,
            'email' => 'test@example.com',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas(table(User::class), [
            'email' => 'test@example.com',
            'deleted_at' => null,
        ]);
    }

    /*
     * Audit.
     */

    public function test_audit_created_when_created()
    {
        $this->fakeEvents();

        $user = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/users', $this->getCreateUserPayload([
            ['role' => Role::NAME_SUPER_ADMIN],
        ]));

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($user, $response) {
            return ($event->getAction() === Audit::ACTION_CREATE) &&
                ($event->getUser()->id === $user->id) &&
                ($event->getModel()->id === $this->getResponseContent($response)['data']['id']);
        });
    }

    /*
     * ==================================================
     * Get a specific user.
     * ==================================================
     */

    public function test_guest_cannot_view_one()
    {
        factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();

        $response = $this->json('GET', "/core/v1/users/{$user->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_can_view_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $response = $this->json('GET', "/core/v1/users/{$user->id}", ['include' => 'user-roles']);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_audit_created_when_viewed()
    {
        $this->fakeEvents();

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $this->json('GET', "/core/v1/users/{$user->id}");

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($user) {
            return ($event->getAction() === Audit::ACTION_READ) &&
                ($event->getUser()->id === $user->id) &&
                ($event->getModel()->id === $user->id);
        });
    }

    /*
     * ==================================================
     * Get the logged in user.
     * ==================================================
     */

    public function test_guest_cannot_view_logged_in_user()
    {
        factory(Service::class)->create();

        $response = $this->json('GET', '/core/v1/users/users');

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_can_view_logged_in_user()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $response = $this->json('GET', '/core/v1/users/user', ['include' => 'user-roles']);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_audit_created_when_logged_in_user_viewed()
    {
        $this->fakeEvents();

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($user);

        $this->json('GET', '/core/v1/users/user');

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($user) {
            return ($event->getAction() === Audit::ACTION_READ) &&
                ($event->getUser()->id === $user->id) &&
                ($event->getModel()->id === $user->id);
        });
    }

    /*
     * ==================================================
     * Update a specific user.
     * ==================================================
     */

    /*
     * Guest Invoked.
     */
    public function test_guest_cannot_update_one()
    {
        factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", $this->getCreateUserPayload([
            ['role' => Role::NAME_SERVICE_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    /*
     * Service Worker Invoked.
     */
    public function test_service_worker_cannot_update_one()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($invoker);

        $user = factory(User::class)->create()->makeSuperAdmin();

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", $this->getCreateUserPayload([
            ['role' => Role::NAME_SUPER_ADMIN],
        ]));

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_worker_can_update_their_own()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($invoker);

        $response = $this->json('PUT', "/core/v1/users/{$invoker->id}", $this->getCreateUserPayload([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]));

        $response->assertStatus(Response::HTTP_OK);
    }

    /*
     * Service Admin Invoked.
     */
    public function test_service_admin_can_update_service_worker()
    {
        $invoker = factory(User::class)->create()->makeServiceAdmin(factory(Service::class)->create());
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_service_admin_can_update_service_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($invoker);

        $subject = factory(User::class)->create()->makeServiceAdmin($service);

        $response = $this->json('PUT', "/core/v1/users/{$subject->id}", [
            'first_name' => $subject->first_name,
            'last_name' => $subject->last_name,
            'email' => $subject->email,
            'phone' => $subject->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $subject->first_name,
            'last_name' => $subject->last_name,
            'email' => $subject->email,
            'phone' => $subject->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_service_admin_cannot_update_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($invoker);

        $subject = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);

        $response = $this->json('PUT', "/core/v1/users/{$subject->id}", [
            'first_name' => $subject->first_name,
            'last_name' => $subject->last_name,
            'email' => $subject->email,
            'phone' => $subject->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /*
     * Organisation Admin Invoked.
     */
    public function test_organisation_admin_can_update_service_worker()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($invoker);

        $user = factory(User::class)->create()->makeServiceWorker($service);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_organisation_admin_can_update_service_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($invoker);

        $user = factory(User::class)->create()->makeServiceAdmin($service);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_organisation_admin_can_update_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($invoker);

        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]);
    }

    public function test_organisation_admin_cannot_update_global_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($invoker);

        $user = factory(User::class)->create()->makeGlobalAdmin($service->organisation);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
                [
                    'role' => Role::NAME_GLOBAL_ADMIN,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /*
     * Global Admin Invoked.
     */

    public function test_global_admin_can_update_service_worker()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_global_admin_can_update_service_admin()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_global_admin_can_update_organisation_admin()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]);
    }

    public function test_global_admin_can_update_global_admin()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
                ['role' => Role::NAME_GLOBAL_ADMIN],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]);
        $response->assertJsonFragment([
            ['role' => Role::NAME_GLOBAL_ADMIN],
        ]);
    }

    public function test_service_and_organisation_permissions_are_applied_when_updating_to_global_admin()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $service1 = factory(Service::class)->create();
        $service2 = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service1->organisation);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                ['role' => Role::NAME_GLOBAL_ADMIN],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);

        $response->assertJsonFragment([
            ['role' => Role::NAME_GLOBAL_ADMIN],
        ]);

        $this->assertTrue($user->isServiceWorker($service1));
        $this->assertTrue($user->isServiceAdmin($service1));
        $this->assertTrue($user->isOrganisationAdmin($service1->organisation));
        $this->assertTrue($user->isServiceWorker($service2));
        $this->assertTrue($user->isServiceAdmin($service2));
        $this->assertTrue($user->isOrganisationAdmin($service2->organisation));
        $this->assertEquals(7, $user->roles()->count());
    }

    public function test_global_admin_cannot_update_super_admin()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
                ['role' => Role::NAME_GLOBAL_ADMIN],
                ['role' => Role::NAME_SUPER_ADMIN],
            ],
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /*
     * Super Admin Invoked.
     */

    public function test_super_admin_can_update_service_worker()
    {
        $invoker = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_super_admin_can_update_service_admin()
    {
        $invoker = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
            ],
        ]);
    }

    public function test_super_admin_can_update_organisation_admin()
    {
        $invoker = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]);
    }

    public function test_super_admin_can_update_global_admin()
    {
        $invoker = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
                ['role' => Role::NAME_GLOBAL_ADMIN],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]);
        $response->assertJsonFragment([
            ['role' => Role::NAME_GLOBAL_ADMIN],
        ]);
    }

    public function test_super_admin_can_update_super_admin()
    {
        $invoker = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();

        $response = $this->json('PUT', "/core/v1/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
                ['role' => Role::NAME_GLOBAL_ADMIN],
                ['role' => Role::NAME_SUPER_ADMIN],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_WORKER,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_SERVICE_ADMIN,
                'service_id' => $service->id,
            ],
        ]);
        $response->assertJsonFragment([
            [
                'role' => Role::NAME_ORGANISATION_ADMIN,
                'organisation_id' => $service->organisation->id,
            ],
        ]);
        $response->assertJsonFragment([
            ['role' => Role::NAME_GLOBAL_ADMIN],
        ]);
        $response->assertJsonFragment([
            ['role' => Role::NAME_SUPER_ADMIN],
        ]);
    }

    /*
     * Audit.
     */

    public function test_audit_created_when_updated()
    {
        $this->fakeEvents();

        $invoker = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $service = factory(Service::class)->create();
        $subject = factory(User::class)->create()->makeSuperAdmin();

        $this->json('PUT', "/core/v1/users/{$subject->id}", [
            'first_name' => $subject->first_name,
            'last_name' => $subject->last_name,
            'email' => $subject->email,
            'phone' => $subject->phone,
            'password' => 'Pa$$w0rd',
            'roles' => [
                [
                    'role' => Role::NAME_SERVICE_WORKER,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_SERVICE_ADMIN,
                    'service_id' => $service->id,
                ],
                [
                    'role' => Role::NAME_ORGANISATION_ADMIN,
                    'organisation_id' => $service->organisation->id,
                ],
                ['role' => Role::NAME_GLOBAL_ADMIN],
                ['role' => Role::NAME_SUPER_ADMIN],
            ],
        ]);

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($invoker, $subject) {
            return ($event->getAction() === Audit::ACTION_UPDATE) &&
                ($event->getUser()->id === $invoker->id) &&
                ($event->getModel()->id === $subject->id);
        });
    }

    /*
     * ==================================================
     * Delete a specific user.
     * ==================================================
     */

    public function test_guest_cannot_delete_service_worker()
    {
        $service = factory(Service::class)->create();
        $subject = factory(User::class)->create()->makeServiceWorker($service);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_delete_service_worker()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceWorker($service);
        $subject = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_can_delete_service_worker()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceAdmin($service);
        $subject = factory(User::class)->create()->makeServiceWorker($service);
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSoftDeleted((new User())->getTable(), ['id' => $subject->id]);
    }

    public function test_guest_cannot_delete_service_admin()
    {
        $service = factory(Service::class)->create();
        $subject = factory(User::class)->create()->makeServiceAdmin($service);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_delete_service_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceWorker($service);
        $subject = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_can_delete_service_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceAdmin($service);
        $subject = factory(User::class)->create()->makeServiceAdmin($service);
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSoftDeleted((new User())->getTable(), ['id' => $subject->id]);
    }

    public function test_guest_cannot_delete_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $subject = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_delete_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceWorker($service);
        $subject = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_cannot_delete_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceAdmin($service);
        $subject = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_organisation_admin_can_delete_organisation_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        $subject = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSoftDeleted((new User())->getTable(), ['id' => $subject->id]);
    }

    public function test_guest_cannot_delete_global_admin()
    {
        $subject = factory(User::class)->create()->makeGlobalAdmin();

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_delete_global_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceWorker($service);
        $subject = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_cannot_delete_global_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceAdmin($service);
        $subject = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_organisation_admin_can_delete_global_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        $subject = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_global_admin_can_delete_global_admin()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        $subject = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSoftDeleted((new User())->getTable(), ['id' => $subject->id]);
    }

    public function test_guest_cannot_delete_super_admin()
    {
        $subject = factory(User::class)->create()->makeGlobalAdmin();

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_delete_super_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceWorker($service);
        $subject = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_cannot_delete_super_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeServiceAdmin($service);
        $subject = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_organisation_admin_can_delete_super_admin()
    {
        $service = factory(Service::class)->create();
        $invoker = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        $subject = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_global_admin_cannot_delete_super_admin()
    {
        $invoker = factory(User::class)->create()->makeGlobalAdmin();
        $subject = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_super_admin_can_delete_super_admin()
    {
        $invoker = factory(User::class)->create()->makeSuperAdmin();
        $subject = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $response = $this->json('DELETE', "/core/v1/users/{$subject->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSoftDeleted((new User())->getTable(), ['id' => $subject->id]);
    }

    public function test_audit_created_when_deleted()
    {
        $this->fakeEvents();

        $invoker = factory(User::class)->create()->makeSuperAdmin();
        $subject = factory(User::class)->create()->makeSuperAdmin();
        Passport::actingAs($invoker);

        $this->json('DELETE', "/core/v1/users/{$subject->id}");

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($invoker, $subject) {
            return ($event->getAction() === Audit::ACTION_DELETE) &&
                ($event->getUser()->id === $invoker->id) &&
                ($event->getModel()->id === $subject->id);
        });
    }

    /*
     * ==================================================
     * Helpers.
     * ==================================================
     */

    /**
     * @param array $roles
     * @return array
     */
    protected function getCreateUserPayload(array $roles): array
    {
        return [
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'email' => $this->faker->safeEmail,
            'phone' => random_uk_phone(),
            'password' => 'Pa$$w0rd',
            'roles' => $roles,
        ];
    }

    /**
     * @param array $roles
     * @return array
     */
    protected function getUpdateUserPayload(array $roles): array
    {
        return [
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'email' => $this->faker->safeEmail,
            'phone' => random_uk_phone(),
            'roles' => $roles,
        ];
    }
}
