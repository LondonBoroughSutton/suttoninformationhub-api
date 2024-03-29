<?php

namespace Tests\Feature;

use App\Events\EndpointHit;
use App\Models\Audit;
use App\Models\File;
use App\Models\HolidayOpeningHour;
use App\Models\Organisation;
use App\Models\RegularOpeningHour;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceLocation;
use App\Models\ServiceRefreshToken;
use App\Models\ServiceTaxonomy;
use App\Models\SocialMedia;
use App\Models\Tag;
use App\Models\Taxonomy;
use App\Models\UpdateRequest;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Faker\Factory as Faker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    /**
     * Create spreadsheets of services
     *
     * @param Illuminate\Support\Collection $services
     * @param array $serviceEligibilities
     * @return null
     **/
    public function createServiceSpreadsheets(\Illuminate\Support\Collection $services, $serviceEligibilities = null)
    {
        $faker = Faker::create('en_GB');

        $headers = [
            'id',
            'organisation_id',
            'name',
            'type',
            'status',
            'intro',
            'description',
            'wait_time',
            'is_free',
            'fees_text',
            'fees_url',
            'testimonial',
            'video_embed',
            'url',
            'contact_name',
            'contact_phone',
            'contact_email',
            'referral_method',
            'referral_email',
            'referral_url',
            'show_referral_disclaimer',
            'referral_button_text',
            'eligibility_age_group_custom',
            'eligibility_disability_custom',
            'eligibility_employment_custom',
            'eligibility_gender_custom',
            'eligibility_housing_custom',
            'eligibility_income_custom',
            'eligibility_language_custom',
            'eligibility_ethnicity_custom',
            'eligibility_other_custom',
            'eligibility_taxonomies',
        ];

        $services = $services->map(function ($service) use ($faker, $serviceEligibilities) {
            $serviceAttributes = $service->getAttributes();
            $serviceAttributes['id'] = $service->id ?: uuid();

            if (is_array($serviceEligibilities) && !empty($serviceEligibilities[$serviceAttributes['id']])) {
                $serviceAttributes['eligibility_taxonomies'] = implode(',', $serviceEligibilities[$serviceAttributes['id']]);
            }
            return $serviceAttributes;
        });

        $spreadsheet = \Tests\Integration\SpreadsheetParserTest::createSpreadsheets($services->all(), $headers);
        \Tests\Integration\SpreadsheetParserTest::writeSpreadsheetsToDisk($spreadsheet, 'test.xlsx', 'test.xls');
    }

    /*
     * List all the services.
     */

    public function test_guest_can_list_them()
    {
        /** @var \App\Models\Service $service */
        $service = factory(Service::class)->create();
        $service->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $service->offerings()->create([
            'offering' => 'Weekly club',
            'order' => 1,
        ]);
        $service->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => Taxonomy::category()->children()->first()->id,
        ]);

        $response = $this->json('GET', '/core/v1/services');

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'id' => $service->id,
            'organisation_id' => $service->organisation_id,
            'has_logo' => $service->hasLogo(),
            'slug' => $service->slug,
            'name' => $service->name,
            'type' => $service->type,
            'status' => $service->status,
            'intro' => $service->intro,
            'description' => $service->description,
            'wait_time' => $service->wait_time,
            'is_free' => $service->is_free,
            'fees_text' => $service->fees_text,
            'fees_url' => $service->fees_url,
            'testimonial' => $service->testimonial,
            'video_embed' => $service->video_embed,
            'url' => $service->url,
            'contact_name' => $service->contact_name,
            'contact_phone' => $service->contact_phone,
            'contact_email' => $service->contact_email,
            'show_referral_disclaimer' => $service->show_referral_disclaimer,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did You Know?',
                    'description' => 'This is a test description',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'gallery_items' => [],
            'category_taxonomies' => [
                [
                    'id' => Taxonomy::category()->children()->first()->id,
                    'parent_id' => Taxonomy::category()->children()->first()->parent_id,
                    'name' => Taxonomy::category()->children()->first()->name,
                    'created_at' => Taxonomy::category()->children()->first()->created_at->format(CarbonImmutable::ISO8601),
                    'updated_at' => Taxonomy::category()->children()->first()->updated_at->format(CarbonImmutable::ISO8601),
                ],
            ],
            'last_modified_at' => $service->last_modified_at->format(CarbonImmutable::ISO8601),
            'created_at' => $service->created_at->format(CarbonImmutable::ISO8601),
        ]);
    }

    public function test_guest_can_filter_by_organisation_id()
    {
        $anotherService = factory(Service::class)->create();
        $service = factory(Service::class)->create();
        $service->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $service->offerings()->create([
            'offering' => 'Weekly club',
            'order' => 1,
        ]);
        $service->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => Taxonomy::category()->children()->first()->id,
        ]);

        $response = $this->json('GET', "/core/v1/services?filter[organisation_id]={$service->organisation_id}");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['id' => $service->id]);
        $response->assertJsonMissing(['id' => $anotherService->id]);
    }

    public function test_guest_can_filter_by_organisation_name()
    {
        $anotherService = factory(Service::class)->create([
            'organisation_id' => factory(Organisation::class)->create(['name' => 'Amazing Place']),
        ]);
        $service = factory(Service::class)->create([
            'organisation_id' => factory(Organisation::class)->create(['name' => 'Interesting House']),
        ]);
        $service->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $service->offerings()->create([
            'offering' => 'Weekly club',
            'order' => 1,
        ]);
        $service->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => Taxonomy::category()->children()->first()->id,
        ]);

        $response = $this->json('GET', "/core/v1/services?filter[organisation_name]={$service->organisation->name}");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['id' => $service->id]);
        $response->assertJsonMissing(['id' => $anotherService->id]);
    }

    public function test_guest_can_filter_by_tag()
    {
        $tag1 = factory(Tag::class)->create();
        $tag2 = factory(Tag::class)->create();
        $tag3 = factory(Tag::class)->create();
        $service1 = factory(Service::class)
            ->states('withUsefulInfo', 'withOfferings', 'withSocialMedia', 'withCategoryTaxonomies')
            ->create();
        $service1->tags()->attach([
            $tag1->id,
            $tag2->id,
        ]);
        $service2 = factory(Service::class)
            ->states('withUsefulInfo', 'withOfferings', 'withSocialMedia', 'withCategoryTaxonomies')
            ->create();
        $service2->tags()->attach([
            $tag2->id,
            $tag3->id,
        ]);
        $service3 = factory(Service::class)
            ->states('withUsefulInfo', 'withOfferings', 'withSocialMedia', 'withCategoryTaxonomies')
            ->create();
        $service3->tags()->attach([
            $tag1->id,
            $tag3->id,
        ]);

        $response = $this->json('GET', "/core/v1/services?filter[tags.slug]={$tag1->slug}");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['id' => $service1->id]);
        $response->assertJsonFragment(['id' => $service3->id]);
        $response->assertJsonMissing(['id' => $service2->id]);

        $response = $this->json('GET', "/core/v1/services?filter[tags.slug]={$tag1->slug},{$tag2->slug}");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonFragment(['id' => $service1->id]);
        $response->assertJsonFragment(['id' => $service2->id]);
        $response->assertJsonFragment(['id' => $service3->id]);
    }

    public function test_audit_created_when_listed()
    {
        $this->fakeEvents();

        $this->json('GET', '/core/v1/services');

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) {
            return ($event->getAction() === Audit::ACTION_READ);
        });
    }

    public function test_guest_can_sort_by_service_name()
    {
        $serviceOne = factory(Service::class)->create(['name' => 'Service A']);
        $serviceTwo = factory(Service::class)->create(['name' => 'Service B']);

        $response = $this->json('GET', '/core/v1/services?sort=-name');
        $data = $this->getResponseContent($response);

        $this->assertEquals($serviceOne->id, $data['data'][1]['id']);
        $this->assertEquals($serviceTwo->id, $data['data'][0]['id']);
    }

    public function test_guest_can_sort_by_organisation_name()
    {
        $serviceOne = factory(Service::class)->create([
            'organisation_id' => factory(Organisation::class)
                ->create(['name' => 'Organisation A'])
                ->id,
        ]);
        $serviceTwo = factory(Service::class)->create([
            'organisation_id' => factory(Organisation::class)
                ->create(['name' => 'Organisation B'])
                ->id,
        ]);

        $response = $this->json('GET', '/core/v1/services?sort=-organisation_name');
        $data = $this->getResponseContent($response);

        $this->assertEquals($serviceOne->organisation_id, $data['data'][1]['organisation_id']);
        $this->assertEquals($serviceTwo->organisation_id, $data['data'][0]['organisation_id']);
    }

    /*
     * Create a service.
     */

    public function test_guest_cannot_create_one()
    {
        $response = $this->json('POST', '/core/v1/services');

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_create_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);

        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/services');

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_cannot_create_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/services');

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_organisation_admin_can_create_an_inactive_one()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);

        $globalAdminUser = factory(User::class)->create()->makeGlobalAdmin();

        $this->assertDatabaseHas((new UpdateRequest())->getTable(), [
            'user_id' => $user->id,
            'updateable_type' => UpdateRequest::NEW_TYPE_SERVICE,
            'updateable_id' => null,
        ]);

        $data = UpdateRequest::query()
            ->where('updateable_type', UpdateRequest::NEW_TYPE_SERVICE)
            ->where('updateable_id', null)
            ->where('user_id', $user->id)
            ->firstOrFail()->data;

        $this->assertEquals($data, $payload);

        // Simulate frontend check by making call with UpdateRequest ID.
        $updateRequestId = json_decode($response->getContent())->id;

        Passport::actingAs($globalAdminUser);

        $updateRequestCheckResponse = $this->get(
            route('core.v1.update-requests.show',
                ['update_request' => $updateRequestId])
        );

        $updateRequestCheckResponse->assertSuccessful();
        $this->assertEquals($data, $payload);
    }

    public function test_global_admin_does_not_create_update_request_when_creating_one()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        //Given that a global admin is logged in
        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ];

        //When they create a service
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        $responseData = json_decode($response->getContent())->data;

        // The service is created
        $this->assertDatabaseHas((new Service())->getTable(), ['id' => $responseData->id]);

        // And no update request was created
        $this->assertEmpty(UpdateRequest::all());
    }

    public function test_organisation_admin_creates_update_request_when_creating_one()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        //Given an organisation admin is logged in
        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];

        //When they create a service
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);

        $responseData = json_decode($response->getContent());

        //Then an update request should be created for the new service
        $updateRequest = UpdateRequest::query()
            ->where('updateable_type', UpdateRequest::NEW_TYPE_SERVICE)
            ->where('updateable_id', null)
            ->firstOrFail();

        $this->assertEquals($updateRequest->data, $payload);

        // Simulate frontend check by making call with UpdateRequest ID.
        $updateRequestId = $responseData->id;

        Passport::actingAs(factory(User::class)->create()->makeGlobalAdmin());

        $updateRequestCheckResponse = $this->get(
            route('core.v1.update-requests.show',
                ['update_request' => $updateRequestId])
        );

        $updateRequestCheckResponse->assertSuccessful();
        $updateRequestResponseData = json_decode($updateRequestCheckResponse->getContent(), true);

        $this->assertEquals($updateRequestResponseData['data'], $payload);
        //And the service should not yet be created
        $this->assertEmpty(Service::all());
    }

    public function test_organisation_admin_can_create_one_with_single_form_of_contact()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => null,
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);
    }

    public function test_organisation_admin_cannot_create_an_active_one()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_organisation_admin_cannot_create_one_with_tags()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        $tag1 = factory(Tag::class)->create();
        $tag2 = factory(Tag::class)->create();
        $tag3 = factory(Tag::class)->create();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [
                [
                    'slug' => $tag1->slug,
                    'label' => $tag1->label,
                ],
                [
                    'slug' => $tag2->slug,
                    'label' => $tag2->label,
                ],
                [
                    'slug' => $tag3->slug,
                    'label' => $tag3->label,
                ],
            ],
            'gallery_items' => [],
            'category_taxonomies' => [],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_taxonomy_hierarchy_works_when_creating()
    {
        $taxonomy = factory(Taxonomy::class)->states('lga-standards')->create();

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [$taxonomy->id],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);
        $service = Service::findOrFail(json_decode($response->getContent(), true)['data']['id']);
        $this->assertDatabaseHas(table(ServiceTaxonomy::class), [
            'service_id' => $service->id,
            'taxonomy_id' => $taxonomy->id,
        ]);
        $this->assertDatabaseHas(table(ServiceTaxonomy::class), [
            'service_id' => $service->id,
            'taxonomy_id' => $taxonomy->parent_id,
        ]);
    }

    public function test_organisation_admin_for_another_organisation_cannot_create_one()
    {
        $anotherOrganisation = factory(Organisation::class)->create();
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $anotherOrganisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_audit_created_when_created()
    {
        $this->fakeEvents();

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $response = $this->json('POST', '/core/v1/services', [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ]);

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($user, $response) {
            return ($event->getAction() === Audit::ACTION_CREATE) &&
                ($event->getUser()->id === $user->id) &&
                ($event->getModel()->id === $this->getResponseContent($response)['data']['id']);
        });
    }

    public function test_global_admin_can_create_an_active_one_with_taxonomies()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);
        $responsePayload = $payload;
        $responsePayload['category_taxonomies'] = [
            [
                'id' => Taxonomy::category()->children()->firstOrFail()->id,
                'parent_id' => Taxonomy::category()->children()->firstOrFail()->parent_id,
                'name' => Taxonomy::category()->children()->firstOrFail()->name,
                'created_at' => Taxonomy::category()->children()->firstOrFail()->created_at->format(CarbonImmutable::ISO8601),
                'updated_at' => Taxonomy::category()->children()->firstOrFail()->updated_at->format(CarbonImmutable::ISO8601),
            ],
        ];
        $response->assertJsonFragment($responsePayload);
    }

    public function test_global_admin_can_create_one_accepting_referrals()
    {
        $organisation = factory(Organisation::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [$taxonomy->id],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);
        $responsePayload = $payload;
        unset($responsePayload['category_taxonomies']);
        $response->assertJsonFragment($responsePayload);
        $response->assertJsonFragment([
            'id' => $taxonomy->id,
            'parent_id' => $taxonomy->parent_id,
            'name' => $taxonomy->name,
            'created_at' => $taxonomy->created_at->format(CarbonImmutable::ISO8601),
            'updated_at' => $taxonomy->updated_at->format(CarbonImmutable::ISO8601),
        ]);
    }

    public function test_global_admin_cannot_create_one_with_referral_disclaimer_showing()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_global_admin_cannot_create_with_non_numeric_phone()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => 'Tel 01234 567890',
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_global_admin_can_create_one_with_tags()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        $taxonomy = factory(Taxonomy::class)->create();
        $tag1 = factory(Tag::class)->create();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [
                [
                    'slug' => $tag1->slug,
                    'label' => $tag1->label,
                ],
            ],
            'gallery_items' => [],
            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        $response->assertJsonFragment([
            'slug' => $tag1->slug,
            'label' => $tag1->label,
        ]);
    }

    public function test_global_admin_can_create_tags_when_creating_one()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        $taxonomy = factory(Taxonomy::class)->create();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [
                [
                    'slug' => 'tag-1',
                    'label' => 'Tag One',
                ],
            ],
            'gallery_items' => [],
            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        $response->assertJsonFragment([
            'slug' => 'tag-1',
            'label' => 'Tag One',
        ]);

        $this->assertDatabaseHas('tags', [
            'slug' => 'tag-1',
            'label' => 'Tag One',
        ]);
    }

    public function test_super_admin_can_create_one_with_referral_disclaimer_showing()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();

        Passport::actingAs($user);

        $taxonomy = factory(Taxonomy::class)->create();

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);
    }

    public function test_slug_is_incremented_when_creating_one_with_duplicate_slug()
    {
        $organisation1 = factory(Organisation::class)->create();
        $organisation2 = factory(Organisation::class)->create();
        $organisation3 = factory(Organisation::class)->create();
        $organisation4 = factory(Organisation::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        //Given that a global admin is logged in
        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation1->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [$taxonomy->id],
        ];

        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        // Slug is not incremented if no clashes exist
        $response->assertJsonFragment([
            'slug' => 'test-service',
        ]);

        $this->assertDatabaseHas((new Service())->getTable(), [
            'organisation_id' => $organisation1->id,
            'slug' => 'test-service',
        ]);

        $payload['organisation_id'] = $organisation2->id;

        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        // slug is incremented when clashes exist
        $response->assertJsonFragment([
            'slug' => 'test-service-1',
        ]);

        $this->assertDatabaseHas((new Service())->getTable(), [
            'organisation_id' => $organisation2->id,
            'slug' => 'test-service-1',
        ]);

        $payload['organisation_id'] = $organisation3->id;
        $payload['slug'] = 'test-service-1';

        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        // slug continues to increment as long as there are clashes
        $response->assertJsonFragment([
            'slug' => 'test-service-2',
        ]);

        $this->assertDatabaseHas((new Service())->getTable(), [
            'organisation_id' => $organisation3->id,
            'slug' => 'test-service-2',
        ]);

        $service = factory(Service::class)->create([
            'slug' => 'demo-service-1',
        ]);

        $payload['organisation_id'] = $organisation4->id;
        $payload['slug'] = 'demo-service';

        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        // slug is permissable if same slug with suffix exists
        $response->assertJsonFragment([
            'slug' => 'demo-service',
        ]);

        $this->assertDatabaseHas((new Service())->getTable(), [
            'organisation_id' => $organisation4->id,
            'slug' => 'demo-service',
        ]);
    }

    /**
     * @test
     */
    public function global_admin_is_added_as_service_admin_when_organisation_admin_creates_one()
    {
        $organisation = factory(Organisation::class)->create();
        $globalAdmin = factory(User::class)->create()->makeGlobalAdmin();
        $orgAdmin = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        //Given an organisation admin is logged in
        Passport::actingAs($orgAdmin);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];

        //When they create a service
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_OK);

        $responseData = json_decode($response->getContent());

        // Simulate frontend check by making call with UpdateRequest ID.
        $updateRequestId = $responseData->id;

        Passport::actingAs($globalAdmin);

        $response = $this->json('PUT', "/core/v1/update-requests/{$updateRequestId}/approve");

        $response->assertStatus(Response::HTTP_OK);

        $service = Service::where('slug', 'test-service')->first();

        $this->assertDatabaseHas((new UserRole)->getTable(), [
            'user_id' => $globalAdmin->id,
            'role_id' => Role::serviceAdmin()->id,
            'service_id' => $service->id,
        ]);

        $this->assertDatabaseHas((new UserRole)->getTable(), [
            'user_id' => $globalAdmin->id,
            'role_id' => Role::serviceWorker()->id,
            'service_id' => $service->id,
        ]);

        $this->assertTrue($globalAdmin->isServiceWorker($service));
        $this->assertTrue($globalAdmin->isServiceAdmin($service));
        $this->assertTrue($globalAdmin->isOrganisationAdmin($service->organisation));
    }

    /**
     * @test
     */
    public function global_admin_is_added_as_service_admin_when_other_global_admin_creates_one()
    {
        $organisation = factory(Organisation::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $globalAdmin1 = factory(User::class)->create()->makeGlobalAdmin();
        $globalAdmin2 = factory(User::class)->create()->makeGlobalAdmin();

        //Given an global admin is logged in
        Passport::actingAs($globalAdmin1);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [
                $taxonomy->id,
            ],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];

        //When they create a service
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(Response::HTTP_CREATED);

        $responseData = $response->json('data');

        $service = Service::find($responseData['id']);

        $this->assertDatabaseHas((new UserRole)->getTable(), [
            'user_id' => $globalAdmin1->id,
            'role_id' => Role::serviceAdmin()->id,
            'service_id' => $responseData['id'],
        ]);

        $this->assertDatabaseHas((new UserRole)->getTable(), [
            'user_id' => $globalAdmin1->id,
            'role_id' => Role::serviceWorker()->id,
            'service_id' => $responseData['id'],
        ]);

        $this->assertDatabaseHas((new UserRole)->getTable(), [
            'user_id' => $globalAdmin2->id,
            'role_id' => Role::serviceAdmin()->id,
            'service_id' => $responseData['id'],
        ]);

        $this->assertDatabaseHas((new UserRole)->getTable(), [
            'user_id' => $globalAdmin2->id,
            'role_id' => Role::serviceWorker()->id,
            'service_id' => $responseData['id'],
        ]);

        $this->assertTrue($globalAdmin1->isServiceWorker($service));
        $this->assertTrue($globalAdmin1->isServiceAdmin($service));
        $this->assertTrue($globalAdmin1->isOrganisationAdmin($service->organisation));

        $this->assertTrue($globalAdmin2->isServiceWorker($service));
        $this->assertTrue($globalAdmin2->isServiceAdmin($service));
        $this->assertTrue($globalAdmin2->isOrganisationAdmin($service->organisation));
    }

    /*
     * Get a specific service.
     */

    public function test_guest_can_view_one()
    {
        $service = factory(Service::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $tag1 = factory(Tag::class)->create();

        $service->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $service->offerings()->create([
            'offering' => 'Weekly club',
            'order' => 1,
        ]);
        $service->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => $taxonomy->id,
        ]);

        $service->tags()->attach([
            $tag1->id,
        ]);

        $response = $this->json('GET', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'id' => $service->id,
            'organisation_id' => $service->organisation_id,
            'has_logo' => $service->hasLogo(),
            'slug' => $service->slug,
            'name' => $service->name,
            'type' => $service->type,
            'status' => $service->status,
            'intro' => $service->intro,
            'description' => $service->description,
            'wait_time' => $service->wait_time,
            'is_free' => $service->is_free,
            'fees_text' => $service->fees_text,
            'fees_url' => $service->fees_url,
            'testimonial' => $service->testimonial,
            'video_embed' => $service->video_embed,
            'url' => $service->url,
            'contact_name' => $service->contact_name,
            'contact_phone' => $service->contact_phone,
            'contact_email' => $service->contact_email,
            'show_referral_disclaimer' => $service->show_referral_disclaimer,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did You Know?',
                    'description' => 'This is a test description',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'category_taxonomies' => [
                [
                    'id' => $taxonomy->id,
                    'parent_id' => $taxonomy->parent_id,
                    'name' => $taxonomy->name,
                    'created_at' => $taxonomy->created_at->format(CarbonImmutable::ISO8601),
                    'updated_at' => $taxonomy->updated_at->format(CarbonImmutable::ISO8601),
                ],
            ],
            'tags' => [
                [
                    'id' => $tag1->id,
                    'slug' => $tag1->slug,
                    'label' => $tag1->label,
                    'created_at' => $tag1->created_at->format(CarbonImmutable::ISO8601),
                    'updated_at' => $tag1->updated_at->format(CarbonImmutable::ISO8601),
                ],
            ],
            'gallery_items' => [],
            'last_modified_at' => $service->last_modified_at->format(CarbonImmutable::ISO8601),
            'created_at' => $service->created_at->format(CarbonImmutable::ISO8601),
        ]);
    }

    public function test_guest_can_view_one_by_slug()
    {
        $service = factory(Service::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $service->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $service->offerings()->create([
            'offering' => 'Weekly club',
            'order' => 1,
        ]);
        $service->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => $taxonomy->id,
        ]);

        $response = $this->json('GET', "/core/v1/services/{$service->slug}");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'id' => $service->id,
            'organisation_id' => $service->organisation_id,
            'has_logo' => $service->hasLogo(),
            'slug' => $service->slug,
            'name' => $service->name,
            'type' => $service->type,
            'status' => $service->status,
            'intro' => $service->intro,
            'description' => $service->description,
            'wait_time' => $service->wait_time,
            'is_free' => $service->is_free,
            'fees_text' => $service->fees_text,
            'fees_url' => $service->fees_url,
            'testimonial' => $service->testimonial,
            'video_embed' => $service->video_embed,
            'url' => $service->url,
            'contact_name' => $service->contact_name,
            'contact_phone' => $service->contact_phone,
            'contact_email' => $service->contact_email,
            'show_referral_disclaimer' => $service->show_referral_disclaimer,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did You Know?',
                    'description' => 'This is a test description',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'category_taxonomies' => [
                [
                    'id' => $taxonomy->id,
                    'parent_id' => $taxonomy->parent_id,
                    'name' => $taxonomy->name,
                    'created_at' => $taxonomy->created_at->format(CarbonImmutable::ISO8601),
                    'updated_at' => $taxonomy->updated_at->format(CarbonImmutable::ISO8601),
                ],
            ],
            'gallery_items' => [],
            'last_modified_at' => $service->last_modified_at->format(CarbonImmutable::ISO8601),
            'created_at' => $service->created_at->format(CarbonImmutable::ISO8601),
        ]);
    }

    public function test_offerings_are_returned_in_order()
    {
        $service = factory(Service::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $service->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $service->offerings()->createMany([
            [
                'offering' => 'First club',
                'order' => 1,
            ],
            [
                'offering' => 'Second club',
                'order' => 2,
            ],
            [
                'offering' => 'Third club',
                'order' => 3,
            ],
        ]);
        $service->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => $taxonomy->id,
        ]);

        $response = $this->json('GET', "/core/v1/services/{$service->slug}");

        $response->assertStatus(Response::HTTP_OK);
        $offerings = $response->json('data.offerings');
        $this->assertEquals(1, $offerings[0]['order']);
        $this->assertEquals(2, $offerings[1]['order']);
        $this->assertEquals(3, $offerings[2]['order']);
    }

    public function test_audit_created_when_viewed()
    {
        $this->fakeEvents();

        $service = factory(Service::class)->create();
        $service->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $service->offerings()->create([
            'offering' => 'Weekly club',
            'order' => 1,
        ]);
        $service->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => factory(Taxonomy::class)->create()->id,
        ]);

        $this->json('GET', "/core/v1/services/{$service->id}");

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($service) {
            return ($event->getAction() === Audit::ACTION_READ) &&
                ($event->getModel()->id === $service->id);
        });
    }

    /*
     * Update a specific service.
     */

    public function test_guest_cannot_update_one()
    {
        $service = factory(Service::class)->create();

        $response = $this->json('PUT', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_update_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);

        Passport::actingAs($user);

        $response = $this->json('PUT', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_can_update_one()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'category_taxonomies' => [
                $taxonomy->id,
                $taxonomy->parent_id,
            ],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['data' => $payload]);
    }

    public function test_service_admin_can_update_one_with_single_form_of_contact()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => null,
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'category_taxonomies' => [
                $taxonomy->id,
                $taxonomy->parent_id,
            ],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['data' => $payload]);
    }

    public function test_service_admin_cannot_update_one_with_tags()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => null,
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [
                [
                    'slug' => 'tag-1',
                    'label' => 'Tag One',
                ],
            ],
            'category_taxonomies' => [
                $taxonomy->id,
                $taxonomy->parent_id,
            ],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_global_admin_can_update_most_fields_for_one()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $tag1 = factory(Tag::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [
                [
                    'slug' => $tag1->slug,
                    'label' => $tag1->label,
                ],
            ],
            'gallery_items' => [],
            'category_taxonomies' => [
                $taxonomy->id,
            ],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['data' => $payload]);
    }

    public function test_global_admin_cannot_update_show_referral_disclaimer_for_one()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_global_admin_cannot_update_with_non_numeric_phone()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => 'Tel 01234 567890',
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [
                $taxonomy->id,
            ],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_audit_created_when_updated()
    {
        $this->fakeEvents();

        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $this->json('PUT', "/core/v1/services/{$service->id}", [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => $service->show_referral_disclaimer,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'category_taxonomies' => [
                $taxonomy->id,
                $taxonomy->parent_id,
            ],
        ]);

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($user, $service) {
            return ($event->getAction() === Audit::ACTION_UPDATE) &&
                ($event->getUser()->id === $user->id) &&
                ($event->getModel()->id === $service->id);
        });
    }

    public function test_service_admin_cannot_update_taxonomies()
    {
        $service = factory(Service::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $newTaxonomy = Taxonomy::category()
            ->children()
            ->where('id', '!=', $taxonomy->id)
            ->firstOrFail();
        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'category_taxonomies' => [
                $taxonomy->id,
                $newTaxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_global_admin_can_update_taxonomies()
    {
        $service = factory(Service::class)->create();
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $newTaxonomy = Taxonomy::category()
            ->children()
            ->where('id', '!=', $taxonomy->id)
            ->firstOrFail();
        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],

            'category_taxonomies' => [
                $taxonomy->id,
                $newTaxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
    }

    public function test_service_admin_can_update_cqc_location_id()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
            'cqc_location_id' => null,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $cqcLocationId = $this->faker->numerify('#-#########');

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $cqcLocationId,
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
                $taxonomy->parent_id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);

        $response->assertJsonFragment(['data' => $payload]);

        $this->assertDatabaseHas(table(UpdateRequest::class), ['updateable_id' => $service->id]);
        $updateRequest = UpdateRequest::where('updateable_id', $service->id)->firstOrFail();
        $this->assertEquals($cqcLocationId, $updateRequest->data['cqc_location_id']);
    }

    public function test_service_admin_cannot_update_status()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_service_admin_cannot_update_slug()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $payload = [
            'slug' => 'new-slug',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_global_admin_can_update_status()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
    }

    public function test_global_admin_cannot_update_slug()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'new-slug',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => $this->faker->safeEmail,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
    }

    public function test_global_admin_can_create_tags_when_updating()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [
                [
                    'slug' => 'tag-1',
                    'label' => 'Tag One',
                ],
            ],
            'gallery_items' => [],
            'category_taxonomies' => [
                $taxonomy->id,
            ],
            'eligibility_types' => [
                'custom' => [],
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'slug' => 'tag-1',
            'label' => 'Tag One',
        ]);

        $this->assertDatabaseHas(table(UpdateRequest::class), ['updateable_id' => $service->id]);
        $updateRequest = UpdateRequest::where('updateable_id', $service->id)->firstOrFail();
        $this->assertEquals([[
            'slug' => 'tag-1',
            'label' => 'Tag One',
        ]], $updateRequest->data['tags']);
    }

    public function test_referral_email_must_be_provided_when_referral_type_is_internal()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'new-slug',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => true,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertArrayHasKey('referral_email', $this->getResponseContent($response)['errors']);
    }

    public function test_service_admin_cannot_update_referral_details()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
                $taxonomy->parent_id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertCount(1, $this->getResponseContent($response)['errors']);
        $this->assertArrayHasKey('referral_method', $this->getResponseContent($response)['errors']);
    }

    public function test_global_admin_can_update_referral_details()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_email' => $this->faker->safeEmail,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => $service->show_referral_disclaimer,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => $this->faker->numerify('#-#########'),
            'useful_infos' => [],
            'offerings' => [],

            'category_taxonomies' => [
                $taxonomy->id,
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
    }

    /*
     * Delete a specific service's logo.
     */

    public function test_service_admin_can_delete_logo()
    {
        /**
         * @var \App\Models\User $user
         */
        $user = factory(User::class)->create();
        $service = factory(Service::class)->create([
            'logo_file_id' => factory(File::class)->create()->id,
        ]);
        $user->makeServiceAdmin($service);
        $payload = [
            'slug' => $service->slug,
            'name' => $service->name,
            'status' => $service->status,
            'intro' => $service->intro,
            'description' => $service->description,
            'wait_time' => $service->wait_time,
            'is_free' => $service->is_free,
            'fees_text' => $service->fees_text,
            'fees_url' => $service->fees_url,
            'testimonial' => $service->testimonial,
            'video_embed' => $service->video_embed,
            'url' => $service->url,
            'contact_name' => $service->contact_name,
            'contact_phone' => $service->contact_phone,
            'contact_email' => $service->contact_email,
            'show_referral_disclaimer' => $service->show_referral_disclaimer,
            'referral_method' => $service->referral_method,
            'referral_button_text' => $service->referral_button_text,
            'referral_email' => $service->referral_email,
            'referral_url' => $service->referral_url,
            'cqc_location_id' => $service->cqc_location_id,
            'useful_infos' => [],

            'category_taxonomies' => [],
            'logo_file_id' => null,
        ];

        Passport::actingAs($user);

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $this->assertDatabaseHas(table(UpdateRequest::class), ['updateable_id' => $service->id]);
        $updateRequest = UpdateRequest::where('updateable_id', $service->id)->firstOrFail();
        $this->assertEquals(null, $updateRequest->data['logo_file_id']);
    }

    public function test_service_admin_can_update_gallery_items()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
            'referral_method' => Service::REFERRAL_METHOD_INTERNAL,
            'referral_email' => $this->faker->safeEmail,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $image = Storage::disk('local')->get('/test-data/image.png');

        Passport::actingAs($user);

        $imageResponse = $this->json('POST', '/core/v1/files', [
            'is_private' => false,
            'mime_type' => 'image/png',
            'file' => 'data:image/png;base64,' . base64_encode($image),
        ]);

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", [
            'gallery_items' => [
                [
                    'file_id' => $this->getResponseContent($imageResponse, 'data.id'),
                ],
            ],
        ]);

        $response->assertStatus(Response::HTTP_OK);
    }

    public function test_only_partial_fields_can_be_updated()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'slug' => 'random-slug',
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['data' => $payload]);
    }

    public function test_fields_removed_for_existing_update_requests()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $responseOne = $this->json('PUT', "/core/v1/services/{$service->id}", [
            'useful_infos' => [
                [
                    'title' => 'Title 1',
                    'description' => 'Description 1',
                    'order' => 1,
                ],
            ],
        ]);
        $responseOne->assertStatus(Response::HTTP_OK);

        $responseTwo = $this->json('PUT', "/core/v1/services/{$service->id}", [
            'useful_infos' => [
                [
                    'title' => 'Title 1',
                    'description' => 'Description 1',
                    'order' => 1,
                ],
                [
                    'title' => 'Title 2',
                    'description' => 'Description 2',
                    'order' => 2,
                ],
            ],
        ]);
        $responseTwo->assertStatus(Response::HTTP_OK);

        $updateRequestOne = UpdateRequest::withTrashed()->findOrFail($this->getResponseContent($responseOne)['id']);
        $updateRequestTwo = UpdateRequest::findOrFail($this->getResponseContent($responseTwo)['id']);

        $this->assertArrayNotHasKey('useful_infos', $updateRequestOne->data);
        $this->assertArrayHasKey('useful_infos', $updateRequestTwo->data);
        $this->assertArrayHasKey('useful_infos.0.title', Arr::dot($updateRequestTwo->data));
        $this->assertArrayHasKey('useful_infos.0.description', Arr::dot($updateRequestTwo->data));
        $this->assertArrayHasKey('useful_infos.0.order', Arr::dot($updateRequestTwo->data));
        $this->assertArrayHasKey('useful_infos.1.title', Arr::dot($updateRequestTwo->data));
        $this->assertArrayHasKey('useful_infos.1.description', Arr::dot($updateRequestTwo->data));
        $this->assertArrayHasKey('useful_infos.1.order', Arr::dot($updateRequestTwo->data));
        $this->assertSoftDeleted($updateRequestOne->getTable(), ['id' => $updateRequestOne->id]);
    }

    public function test_referral_url_required_when_referral_method_not_updated_with_it()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
            'referral_method' => Service::REFERRAL_METHOD_EXTERNAL,
            'referral_url' => $this->faker->url,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", [
            'referral_url' => null,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_organisation_admin_cannot_update_organisation_id()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);

        Passport::actingAs($user);

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", [
            'organisation_id' => factory(Organisation::class)->create()->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_global_admin_can_update_organisation_id()
    {
        $originalOrganisation = factory(Organisation::class)->create([
            'name' => 'Original Organisation',
        ]);
        $service = factory(Service::class)->create([
            'organisation_id' => $originalOrganisation->id,
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $newOrganisation = factory(Organisation::class)->create([
            'name' => 'New Organisation',
        ]);
        $globalAdmin = factory(User::class)->create()->makeGlobalAdmin();
        $originalOrganisationAdmin = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);
        $newOrganisationAdmin = factory(User::class)->create()->makeOrganisationAdmin($newOrganisation);

        $this->assertFalse($newOrganisationAdmin->isServiceAdmin($service));
        $this->assertTrue($originalOrganisationAdmin->isServiceAdmin($service));

        Passport::actingAs($globalAdmin);

        $payload = [
            'organisation_id' => $newOrganisation->id,
        ];

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['data' => $payload]);

        $service->refresh();

        $this->assertTrue($newOrganisationAdmin->isServiceAdmin($service));
        $this->assertFalse($originalOrganisationAdmin->isServiceAdmin($service));
    }

    public function test_global_admin_can_update_organisation_id_with_preview_only()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => factory(Organisation::class)->create()->id,
        ];

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", array_merge(
            $payload,
            ['preview' => true]
        ));

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['id' => null, 'data' => $payload]);
    }

    public function test_global_admin_can_update_one_with_auto_approval()
    {
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $taxonomy = factory(Taxonomy::class)->create();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => factory(Organisation::class)->create()->id,
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment(['data' => $payload]);

        $data = UpdateRequest::query()
            ->where('updateable_type', UpdateRequest::EXISTING_TYPE_SERVICE)
            ->where('updateable_id', $service->id)
            ->firstOrFail()->data;
        $this->assertEquals($data, $payload);

        // Simulate frontend check by making call with UpdateRequest ID.
        $updateRequestId = json_decode($response->getContent())->id;
        Passport::actingAs($user);

        $updateRequestCheckResponse = $this->get(
            route('core.v1.update-requests.show',
                ['update_request' => $updateRequestId])
        );

        $updateRequestCheckResponse->assertSuccessful();
        $updateRequestResponseData = json_decode($updateRequestCheckResponse->getContent());

        // Update request should already have been approved.
        $this->assertNotNull($updateRequestResponseData->approved_at);
    }

    /*
     * Delete a specific service.
     */

    public function test_guest_cannot_delete_one()
    {
        $service = factory(Service::class)->create();

        $response = $this->json('DELETE', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_delete_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);

        Passport::actingAs($user);

        $response = $this->json('DELETE', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_cannot_delete_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $response = $this->json('DELETE', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_organisation_admin_cannot_delete_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($service->organisation);

        Passport::actingAs($user);

        $response = $this->json('DELETE', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_global_admin_can_delete_one()
    {
        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $response = $this->json('DELETE', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertDatabaseMissing((new Service())->getTable(), ['id' => $service->id]);
    }

    public function test_audit_created_when_deleted()
    {
        $this->fakeEvents();

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeSuperAdmin();

        Passport::actingAs($user);

        $this->json('DELETE', "/core/v1/services/{$service->id}");

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($user, $service) {
            return ($event->getAction() === Audit::ACTION_DELETE) &&
                ($event->getUser()->id === $user->id) &&
                ($event->getModel()->id === $service->id);
        });
    }

    public function test_service_can_be_deleted_when_service_location_has_opening_hours()
    {
        $service = factory(Service::class)->create();
        $serviceLocation = factory(ServiceLocation::class)->create([
            'service_id' => $service->id,
        ]);
        factory(RegularOpeningHour::class)->create([
            'service_location_id' => $serviceLocation->id,
        ]);
        factory(HolidayOpeningHour::class)->create([
            'service_location_id' => $serviceLocation->id,
        ]);
        $user = factory(User::class)->create()->makeSuperAdmin();

        Passport::actingAs($user);

        $response = $this->json('DELETE', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertDatabaseMissing((new Service())->getTable(), ['id' => $service->id]);
    }

    public function test_service_can_be_deleted_when_disabled()
    {
        $service = factory(Service::class)->create([
            'status' => Service::STATUS_INACTIVE,
        ]);

        $user = factory(User::class)->create()->makeSuperAdmin();

        Passport::actingAs($user);

        $response = $this->json('DELETE', "/core/v1/services/{$service->id}");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertDatabaseMissing((new Service())->getTable(), ['id' => $service->id]);
    }

    /*
     * Refresh service.
     */

    public function test_guest_without_token_cannot_refresh()
    {
        $service = factory(Service::class)->create();

        $response = $this->putJson("/core/v1/services/{$service->id}/refresh");

        $response->assertStatus(Response::HTTP_NOT_FOUND);
    }

    public function test_guest_with_invalid_token_cannot_refresh()
    {
        $service = factory(Service::class)->create();

        $response = $this->putJson("/core/v1/services/{$service->id}/refresh", [
            'token' => 'invalid-token',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_guest_with_valid_token_can_refresh()
    {
        $now = Date::now();
        Date::setTestNow($now);

        $service = factory(Service::class)->create([
            'last_modified_at' => Date::now()->subMonths(6),
        ]);

        $response = $this->putJson("/core/v1/services/{$service->id}/refresh", [
            'token' => factory(ServiceRefreshToken::class)->create([
                'service_id' => $service->id,
            ])->id,
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment([
            'last_modified_at' => $now->format(CarbonImmutable::ISO8601),
        ]);
    }

    /*
     * List all the related services.
     */

    public function test_guest_can_list_related()
    {
        $taxonomyOne = factory(Taxonomy::class)->create();
        $taxonomyTwo = factory(Taxonomy::class)->create();
        $taxonomyThree = factory(Taxonomy::class)->create();

        $service = factory(Service::class)->create();
        $service->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyOne->id]);
        $service->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyTwo->id]);
        $service->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyThree->id]);

        $relatedService = factory(Service::class)->create();
        $relatedService->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $relatedService->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $relatedService->serviceGalleryItems()->create([
            'file_id' => factory(File::class)->create()->id,
        ]);
        $relatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyOne->id]);
        $relatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyTwo->id]);
        $relatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyThree->id]);

        $unrelatedService = factory(Service::class)->create();
        $unrelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyOne->id]);
        $unrelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyTwo->id]);

        $inactiveService = factory(Service::class)->create([
            'status' => Service::STATUS_INACTIVE,
        ]);
        $inactiveService->usefulInfos()->create([
            'title' => 'Did You Know?',
            'description' => 'This is a test description',
            'order' => 1,
        ]);
        $inactiveService->socialMedias()->create([
            'type' => SocialMedia::TYPE_INSTAGRAM,
            'url' => 'https://www.instagram.com/ayupdigital/',
        ]);
        $inactiveService->serviceGalleryItems()->create([
            'file_id' => factory(File::class)->create()->id,
        ]);
        $inactiveService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyOne->id]);
        $inactiveService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyTwo->id]);
        $inactiveService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyThree->id]);

        $response = $this->json('GET', "/core/v1/services/{$service->id}/related");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'organisation_id',
                    'has_logo',
                    'name',
                    'slug',
                    'type',
                    'status',
                    'intro',
                    'description',
                    'wait_time',
                    'is_free',
                    'fees_text',
                    'fees_url',
                    'testimonial',
                    'video_embed',
                    'url',
                    'contact_name',
                    'contact_phone',
                    'contact_email',
                    'show_referral_disclaimer',
                    'referral_method',
                    'referral_button_text',
                    'referral_email',
                    'referral_url',
                    'useful_infos' => [
                        [
                            'title',
                            'description',
                            'order',
                        ],
                    ],
                    'gallery_items' => [
                        [
                            'file_id',
                            'url',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                    'category_taxonomies' => [
                        [
                            'id',
                            'parent_id',
                            'name',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                    'last_modified_at',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'path',
                'per_page',
                'to',
                'total',
            ],
            'links' => [
                'first',
                'last',
                'prev',
                'next',
            ],
        ]);

        $response->assertJsonFragment(['id' => $relatedService->id]);
        $response->assertJsonMissing(['id' => $unrelatedService->id]);
        $response->assertJsonMissing(['id' => $inactiveService->id]);
    }

    public function test_related_services_order_by_taxonomy_depth()
    {
        // Create taxonomies.
        $taxonomy = factory(Taxonomy::class)->create();
        $taxonomyOneDepthOne = $taxonomy->children()->create([
            'name' => 'Taxonomy One',
            'order' => 1,
            'depth' => 1,
        ]);
        $taxonomyTwoDepthOne = $taxonomy->children()->create([
            'name' => 'Taxonomy Two',
            'order' => 1,
            'depth' => 1,
        ]);
        $taxonomyThreeDepthOne = $taxonomy->children()->create([
            'name' => 'Taxonomy Three',
            'order' => 1,
            'depth' => 1,
        ]);
        $taxonomyFourDepthTwo = $taxonomyOneDepthOne->children()->create([
            'name' => 'Taxonomy Four',
            'order' => 1,
            'depth' => 2,
        ]);

        // Create service.
        $service = factory(Service::class)->create();
        $service->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyOneDepthOne->id]);
        $service->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyTwoDepthOne->id]);
        $service->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyThreeDepthOne->id]);
        $service->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyFourDepthTwo->id]);

        // Create closely related service.
        $closelyRelatedService = factory(Service::class)->create([
            'name' => 'Beta',
        ]);
        $closelyRelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyOneDepthOne->id]);
        $closelyRelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyTwoDepthOne->id]);
        $closelyRelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyFourDepthTwo->id]);

        // Create distantly related service.
        $distantlyRelatedService = factory(Service::class)->create([
            'name' => 'Alpha',
        ]);
        $distantlyRelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyOneDepthOne->id]);
        $distantlyRelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyTwoDepthOne->id]);
        $distantlyRelatedService->serviceTaxonomies()->create(['taxonomy_id' => $taxonomyThreeDepthOne->id]);

        $response = $this->json('GET', "/core/v1/services/{$service->id}/related");
        $response->assertStatus(Response::HTTP_OK);

        $services = $this->getResponseContent($response, 'data');

        $this->assertCount(2, $services);
        $this->assertSame($closelyRelatedService->id, $services[0]['id']);
        $this->assertSame($distantlyRelatedService->id, $services[1]['id']);
    }

    /*
     * Get a specific service's logo.
     */

    public function test_guest_can_view_logo()
    {
        $service = factory(Service::class)->create();

        $response = $this->get("/core/v1/services/{$service->id}/logo.png");

        $response->assertStatus(Response::HTTP_OK);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_audit_created_when_logo_viewed()
    {
        $this->fakeEvents();

        $service = factory(Service::class)->create();

        $this->get("/core/v1/services/{$service->id}/logo.png");

        Event::assertDispatched(EndpointHit::class, function (EndpointHit $event) use ($service) {
            return ($event->getAction() === Audit::ACTION_READ) &&
                ($event->getModel()->id === $service->id);
        });
    }

    /*
     * Upload a specific service's logo.
     */

    public function test_organisation_admin_can_upload_logo()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create();
        $user->makeOrganisationAdmin($organisation);
        $image = Storage::disk('local')->get('/test-data/image.png');

        Passport::actingAs($user);

        $imageResponse = $this->json('POST', '/core/v1/files', [
            'is_private' => false,
            'mime_type' => 'image/png',
            'file' => 'data:image/png;base64,' . base64_encode($image),
        ]);

        $response = $this->json('POST', '/core/v1/services', [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_INACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => null,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [],
            'logo_file_id' => $this->getResponseContent($imageResponse, 'data.id'),
        ]);

        $serviceId = $this->getResponseContent($response, 'data.id');

        $response->assertStatus(Response::HTTP_OK);
        $this->assertDatabaseHas(table(UpdateRequest::class), ['updateable_id' => $serviceId]);
        $updateRequest = UpdateRequest::where('updateable_id', $serviceId)->firstOrFail();
        $this->assertEquals($this->getResponseContent($imageResponse, 'data.id'), $updateRequest->data['logo_file_id']);
    }

    /*
     * Get a specific service's gallery item.
     */

    public function test_guest_can_view_gallery_item()
    {
        /** @var \App\Models\File $file */
        $file = factory(File::class)->create([
            'filename' => 'random-name.png',
            'mime_type' => 'image/png',
        ])->upload(
            Storage::disk('local')->get('/test-data/image.png')
        );

        /** @var \App\Models\Service $service */
        $service = factory(Service::class)->create();

        /** @var \App\Models\ServiceGalleryItem $serviceGalleryItem */
        $serviceGalleryItem = $service->serviceGalleryItems()->create([
            'file_id' => $file->id,
        ]);

        $response = $this->get($serviceGalleryItem->url());

        $response->assertStatus(Response::HTTP_OK);
        $response->assertHeader('Content-Type', 'image/png');
    }

    /**
     * Bulk import services
     */

    public function test_guest_cannot_bulk_import()
    {
        Storage::fake('local');

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_INACTIVE,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_service_worker_cannot_bulk_import()
    {
        Storage::fake('local');

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceWorker($service);

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make();

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_service_admin_cannot_bulk_import()
    {
        Storage::fake('local');

        $service = factory(Service::class)->create();
        $user = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_INACTIVE,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_organisation_admin_from_other_organisation_cannot_bulk_import()
    {
        Storage::fake('local');

        $organisation1 = factory(Organisation::class)->create();
        $organisation2 = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation1);

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation2->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'organisation_id' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_organisation_admin_can_bulk_import()
    {
        Storage::fake('local');

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services, $organisation->id);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
    }

    public function test_global_admin_can_bulk_import()
    {
        Storage::fake('local');

        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make();

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
    }

    public function test_super_admin_can_bulk_import()
    {
        Storage::fake('local');

        $user = factory(User::class)->create()->makeSuperAdmin();

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make();

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
    }

    public function test_global_admin_can_view_bulk_imported_services()
    {
        Storage::fake('local');

        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make();

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);

        $response = $this->json('GET', "/core/v1/services?include=organisation&filter=[has_permission]=true&page=1&sort=name");

        $response->assertStatus(Response::HTTP_OK);

        $response->assertJsonFragment([
            'name' => $services->get(0)->name,
        ]);

        $response->assertJsonFragment([
            'name' => $services->get(1)->name,
        ]);

        $service1 = Service::where('name', $services->get(0)->name)->first();
        $service2 = Service::where('name', $services->get(1)->name)->first();

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceWorker()->id,
            'service_id' => $service1->id,
            'organisation_id' => $service1->organisation_id,
        ]);

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceWorker()->id,
            'service_id' => $service2->id,
            'organisation_id' => $service2->organisation_id,
        ]);

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceAdmin()->id,
            'service_id' => $service1->id,
            'organisation_id' => $service1->organisation_id,
        ]);

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceAdmin()->id,
            'service_id' => $service2->id,
            'organisation_id' => $service2->organisation_id,
        ]);
    }

    public function test_super_admin_can_view_bulk_imported_services()
    {
        Storage::fake('local');

        $user = factory(User::class)->create()->makeSuperAdmin();

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make();

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);

        $response = $this->json('GET', "/core/v1/services?include=organisation&filter=[has_permission]=true&page=1&sort=name");

        $response->assertStatus(Response::HTTP_OK);

        $response->assertJsonFragment([
            'name' => $services->get(0)->name,
        ]);

        $response->assertJsonFragment([
            'name' => $services->get(1)->name,
        ]);

        $service1 = Service::where('name', $services->get(0)->name)->first();
        $service2 = Service::where('name', $services->get(1)->name)->first();

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceWorker()->id,
            'service_id' => $service1->id,
            'organisation_id' => $service1->organisation_id,
        ]);

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceWorker()->id,
            'service_id' => $service2->id,
            'organisation_id' => $service2->organisation_id,
        ]);

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceAdmin()->id,
            'service_id' => $service1->id,
            'organisation_id' => $service1->organisation_id,
        ]);

        $this->assertDatabaseHas((new UserRole())->getTable(), [
            'user_id' => $user->id,
            'role_id' => Role::serviceAdmin()->id,
            'service_id' => $service2->id,
            'organisation_id' => $service2->organisation_id,
        ]);
    }

    public function test_validate_file_import_type()
    {
        Storage::fake('local');

        $organisation = factory(Organisation::class)->create();

        $invalidFieldTypes = [
            ['spreadsheet' => 'This is a string'],
            ['spreadsheet' => 1],
            ['spreadsheet' => ['foo' => 'bar']],
            ['spreadsheet' => UploadedFile::fake()->create('dummy.doc', 3000)],
            ['spreadsheet' => UploadedFile::fake()->create('dummy.txt', 3000)],
            ['spreadsheet' => UploadedFile::fake()->create('dummy.csv', 3000)],
        ];

        $user = factory(User::class)->create()->makeSuperAdmin();
        $organisation = factory(Organisation::class)->create();

        Passport::actingAs($user);

        foreach ($invalidFieldTypes as $data) {
            $data['organisation_id'] = $organisation->id;
            $response = $this->json('POST', "/core/v1/services/import", $data);
            $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $services = factory(Service::class, 2)->make([
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'data' => [
                'imported_row_count' => 2,
            ],
        ]);

        $services = factory(Service::class, 2)->make([
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/octet-stream;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'data' => [
                'imported_row_count' => 2,
            ],
        ]);

        $services = factory(Service::class, 2)->make([
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'data' => [
                'imported_row_count' => 2,
            ],
        ]);
    }

    public function test_validate_file_import_service_fields()
    {
        Storage::fake('local');
        $faker = Faker::create('en_GB');

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $services = factory(Service::class, 2)->make([
            'organisation_id' => uuid(),
            'name' => '',
            'type' => '',
            'status' => Service::STATUS_ACTIVE,
            'intro' => '',
            'description' => '',
            'url' => '',
            'referral_method' => '',
        ]);

        foreach ($services as &$service) {
            $service->id = $faker->word;
        }

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'id' => [],
                                'organisation_id' => [],
                                'name' => [],
                                'type' => [],
                                'status' => [],
                                'intro' => [],
                                'description' => [],
                                'url' => [],
                                'referral_method' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $services = factory(Service::class, 2)->make([
            'organisation_id' => uuid(),
            'name' => '',
            'type' => '',
            'status' => Service::STATUS_ACTIVE,
            'intro' => '',
            'description' => '',
            'url' => '',
            'referral_method' => '',
            'referral_email' => $faker->word,
            'referral_url' => $faker->word,
        ]);

        foreach ($services as &$service) {
            $service->id = $faker->word;
        }

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'id' => [],
                                'organisation_id' => [],
                                'name' => [],
                                'type' => [],
                                'status' => [],
                                'intro' => [],
                                'description' => [],
                                'url' => [],
                                'referral_method' => [],
                                'referral_email' => [],
                                'referral_url' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = factory(Service::class)->make([
            'id' => factory(Service::class)->create()->id,
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets(collect([$service]));

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'id' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'id' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @test
     */
    public function validate_file_import_duplicate_service_ids()
    {
        Storage::fake('local');

        $user = factory(User::class)->create();
        $user->makeSuperAdmin();
        Passport::actingAs($user);

        $service = factory(Service::class)->create();

        $uuid = uuid();

        $services = collect([
            $service,
            factory(Service::class)->make([
                'id' => $uuid,
            ]),
            factory(Service::class)->make([
                'id' => $uuid,
            ]),
        ]);

        $this->createServiceSpreadsheets($services);

        $response = $this->json('POST', "/core/v1/services/import", [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $headers = [
            'id',
            'name',
            'description',
            'url',
            'email',
            'phone',
        ];

        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'id' => [
                                    'The id has already been taken.',
                                ],
                            ],
                        ],
                        [
                            'row' => [],
                            'errors' => [
                                'id' => [
                                    'The ID is used elsewhere in the spreadsheet.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_validate_file_import_service_field_global_admin_permissions()
    {
        Storage::fake('local');
        $faker = Faker::create('en_GB');

        $organisation = factory(Organisation::class)->create();
        $organisationAdminUser = factory(User::class)->create()->makeOrganisationAdmin($organisation);
        $globalAdminUser = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($organisationAdminUser);

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_ACTIVE,
            'organisation_id' => $organisation->id,
            'referral_method' => Service::REFERRAL_METHOD_EXTERNAL,
            'referral_button_text' => $faker->word,
            'referral_email' => $faker->email,
            'referral_url' => $faker->url,
            'show_referral_disclaimer' => '1',
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'status' => [],
                                'referral_method' => [],
                                'referral_button_text' => [],
                                'referral_email' => [],
                                'referral_url' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'status' => [],
                                'referral_method' => [],
                                'referral_button_text' => [],
                                'referral_email' => [],
                                'referral_url' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Passport::actingAs($globalAdminUser);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_ACTIVE,
            'referral_method' => Service::REFERRAL_METHOD_EXTERNAL,
            'referral_button_text' => $faker->word,
            'referral_email' => $faker->email,
            'referral_url' => $faker->url,
            'show_referral_disclaimer' => '1',
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
    }

    public function test_validate_file_import_service_field_super_admin_permissions()
    {
        Storage::fake('local');
        $faker = Faker::create('en_GB');

        $globalAdminUser = factory(User::class)->create()->makeGlobalAdmin();
        $superAdminUser = factory(User::class)->create()->makeSuperAdmin();

        Passport::actingAs($globalAdminUser);

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_ACTIVE,
            'referral_method' => Service::REFERRAL_METHOD_EXTERNAL,
            'referral_button_text' => $faker->word,
            'referral_email' => $faker->email,
            'referral_url' => $faker->url,
            'show_referral_disclaimer' => '0',
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'show_referral_disclaimer' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'show_referral_disclaimer' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Passport::actingAs($superAdminUser);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);

        $services = factory(Service::class, 2)->make([
            'status' => Service::STATUS_ACTIVE,
            'referral_method' => Service::REFERRAL_METHOD_EXTERNAL,
            'referral_button_text' => $faker->word,
            'referral_email' => $faker->email,
            'referral_url' => $faker->url,
            'show_referral_disclaimer' => '0',
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
    }

    /**
     * @test
     */
    public function service_file_import_creates_service_eligibility_relations()
    {
        Storage::fake('local');

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);
        $serviceId = uuid();

        Passport::actingAs($user);

        $service = factory(Service::class)->states(['withCustomEligibilities'])->make([
            'id' => $serviceId,
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $serviceEligibilityTaxonomyIds = Taxonomy::serviceEligibility()->children->map(function ($serviceEligibility) {
            return $serviceEligibility->children->first()->id;
        });

        $this->createServiceSpreadsheets(collect([$service]), [$serviceId => $serviceEligibilityTaxonomyIds->all()]);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);

        $response->assertJson([
            'data' => [
                'imported_row_count' => 1,
            ],
        ]);

        foreach ($serviceEligibilityTaxonomyIds as $serviceEligibilityId) {
            $this->assertDatabaseHas('service_eligibilities', [
                'service_id' => $serviceId,
                'taxonomy_id' => $serviceEligibilityId,
            ]);
        }
    }

    /**
     * @test
     */
    public function service_file_import_rejects_invalid_service_eligibility_relations()
    {
        Storage::fake('local');

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);
        $serviceId = uuid();

        Passport::actingAs($user);

        $service = factory(Service::class)->states(['withCustomEligibilities'])->make([
            'id' => $serviceId,
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $invalidServiceEligibilityTaxonomyIds = [uuid(), uuid(), uuid()];

        $this->createServiceSpreadsheets(collect([$service]), [$serviceId => $invalidServiceEligibilityTaxonomyIds]);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'eligibility_taxonomies' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        foreach ($invalidServiceEligibilityTaxonomyIds as $invalidServiceEligibilityId) {
            $this->assertDatabaseMissing('service_eligibilities', [
                'service_id' => $serviceId,
                'taxonomy_id' => $invalidServiceEligibilityId,
            ]);
        }

        $categoryTaxonomyIds = [];
        foreach (Taxonomy::category()->children as $taxonomyCategory) {
            if (!$taxonomyCategory->children->isEmpty()) {
                $categoryTaxonomyIds[] = $taxonomyCategory->children->first()->id;
            }
        }

        $this->createServiceSpreadsheets(collect([$service]), [$serviceId => $categoryTaxonomyIds]);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson([
            'data' => [
                'errors' => [
                    'spreadsheet' => [
                        [
                            'row' => [],
                            'errors' => [
                                'eligibility_taxonomies' => [
                                    trans_choice(
                                        'validation.custom.service_eligibilities.not_found',
                                        count($categoryTaxonomyIds),
                                        ['ids' => implode(', ', $categoryTaxonomyIds)]
                                    ),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        foreach ($categoryTaxonomyIds as $categoryTaxonomyId) {
            $this->assertDatabaseMissing('service_eligibilities', [
                'service_id' => $serviceId,
                'taxonomy_id' => $categoryTaxonomyId,
            ]);
        }
    }

    public function test_services_file_import_100rows()
    {
        Storage::fake('local');

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $services = factory(Service::class, 100)->make([
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'data' => [
                'imported_row_count' => 100,
            ],
        ]);

        $services = factory(Service::class, 100)->make([
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'data' => [
                'imported_row_count' => 100,
            ],
        ]);
    }

    /**
     * @group slow
     */
    public function test_services_file_import_5krows()
    {
        Storage::fake('local');

        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeOrganisationAdmin($organisation);

        Passport::actingAs($user);

        $services = factory(Service::class, 5000)->make([
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.ms-excel;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xls'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'data' => [
                'imported_row_count' => 5000,
            ],
        ]);

        $services = factory(Service::class, 5000)->make([
            'status' => Service::STATUS_INACTIVE,
            'organisation_id' => $organisation->id,
        ]);

        $this->createServiceSpreadsheets($services);

        $data = [
            'spreadsheet' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode(file_get_contents(Storage::disk('local')->path('test.xlsx'))),
        ];

        $response = $this->json('POST', "/core/v1/services/import", $data);

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'data' => [
                'imported_row_count' => 5000,
            ],
        ]);
    }

    public function test_service_eligiblity_custom_fields_schema_on_index()
    {
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withCategoryTaxonomies'
            )
            ->create();

        $service->save();

        $response = $this->get(route('core.v1.services.index'));

        $response->assertJsonFragment([
            'eligibility_types' => [
                'custom' => [
                    'age_group' => $service->eligibility_age_group_custom,
                    'disability' => $service->eligibility_disability_custom,
                    'ethnicity' => $service->eligibility_ethnicity_custom,
                    'gender' => $service->eligibility_gender_custom,
                    'income' => $service->eligibility_income_custom,
                    'language' => $service->eligibility_language_custom,
                    'housing' => $service->eligibility_housing_custom,
                    'other' => $service->eligibility_other_custom,
                ],
                'taxonomies' => [],
            ],
        ]);
    }

    /**
     * Service Eligibilities
     */

    public function test_service_eligiblity_taxonomy_id_schema_on_index()
    {
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        $taxonomyIds = $service->serviceEligibilities()->pluck('taxonomy_id')->all();

        $response = $this->get(route('core.v1.services.index'));

        $response->assertJsonFragment([
            'eligibility_types' => [
                'custom' => [
                    'age_group' => null,
                    'disability' => null,
                    'ethnicity' => null,
                    'gender' => null,
                    'income' => null,
                    'language' => null,
                    'housing' => null,
                    'other' => null,
                ],
                'taxonomies' => $taxonomyIds,
            ],
        ]);
    }

    public function test_service_eligibility_taxonomy_and_custom_fields_schema_on_index()
    {
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withCategoryTaxonomies',
                'withEligibilityTaxonomies'
            )
            ->create();

        $taxonomyIds = $service->serviceEligibilities()->pluck('taxonomy_id')->all();
        sort($taxonomyIds, SORT_STRING);

        $response = $this->get(route('core.v1.services.index'));

        $response->assertJsonFragment([
            'eligibility_types' => [
                'custom' => [
                    'age_group' => $service->eligibility_age_group_custom,
                    'disability' => $service->eligibility_disability_custom,
                    'ethnicity' => $service->eligibility_ethnicity_custom,
                    'gender' => $service->eligibility_gender_custom,
                    'income' => $service->eligibility_income_custom,
                    'language' => $service->eligibility_language_custom,
                    'housing' => $service->eligibility_housing_custom,
                    'other' => $service->eligibility_other_custom,
                ],
                'taxonomies' => $taxonomyIds,
            ],
        ]);
    }

    public function test_service_eligiblity_custom_fields_schema_on_show()
    {
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withCategoryTaxonomies'
            )
            ->create();

        $response = $this->get(route('core.v1.services.show', $service->id));

        $response->assertJsonFragment([
            'eligibility_types' => [
                'custom' => [
                    'age_group' => $service->eligibility_age_group_custom,
                    'disability' => $service->eligibility_disability_custom,
                    'ethnicity' => $service->eligibility_ethnicity_custom,
                    'gender' => $service->eligibility_gender_custom,
                    'income' => $service->eligibility_income_custom,
                    'language' => $service->eligibility_language_custom,
                    'housing' => $service->eligibility_housing_custom,
                    'other' => $service->eligibility_other_custom,
                ],
                'taxonomies' => [],
            ],
        ]);
    }

    public function test_service_eligiblity_taxonomy_id_schema_on_show()
    {
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCategoryTaxonomies',
                'withEligibilityTaxonomies'
            )
            ->create();

        $taxonomyIds = $service->serviceEligibilities()->pluck('taxonomy_id')->all();

        $response = $this->get(route('core.v1.services.show', $service->id));

        $response->assertJsonFragment([
            'eligibility_types' => [
                'custom' => [
                    'age_group' => null,
                    'disability' => null,
                    'ethnicity' => null,
                    'gender' => null,
                    'income' => null,
                    'language' => null,
                    'housing' => null,
                    'other' => null,
                ],
                'taxonomies' => $taxonomyIds,
            ],
        ]);
    }

    public function test_service_eligibility_taxonomy_and_custom_fields_schema_on_show()
    {
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withCategoryTaxonomies',
                'withEligibilityTaxonomies'
            )
            ->create();

        $taxonomyIds = $service->serviceEligibilities()->pluck('taxonomy_id')->all();

        $response = $this->get(route('core.v1.services.show', $service->id));

        $response->assertJsonFragment([
            'eligibility_types' => [
                'custom' => [
                    'age_group' => $service->eligibility_age_group_custom,
                    'disability' => $service->eligibility_disability_custom,
                    'ethnicity' => $service->eligibility_ethnicity_custom,
                    'gender' => $service->eligibility_gender_custom,
                    'income' => $service->eligibility_income_custom,
                    'language' => $service->eligibility_language_custom,
                    'housing' => $service->eligibility_housing_custom,
                    'other' => $service->eligibility_other_custom,
                ],
                'taxonomies' => $taxonomyIds,
            ],
        ]);
    }

    public function test_create_service_with_eligibility_taxonomies()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        $taxonomyIds = Taxonomy::serviceEligibility()
            ->children
            ->map(function ($taxonomy) {
                return $taxonomy
                    ->children()
                    ->inRandomOrder()
                    ->pluck('id')
                    ->first();
            })->toArray();

        sort($taxonomyIds, SORT_STRING);

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => null,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ];

        $taxonomyPayload = [
            'eligibility_types' => [
                'custom' => [
                    'age_group' => null,
                    'disability' => null,
                    'ethnicity' => null,
                    'gender' => null,
                    'income' => null,
                    'language' => null,
                    'housing' => null,
                    'other' => null,
                ],
                'taxonomies' => $taxonomyIds,
            ],
        ];

        $payload = array_merge($taxonomyPayload, $payload);

        $response = $this->json('POST', '/core/v1/services', $payload);
        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonFragment($taxonomyPayload);
    }

    public function test_create_service_with_custom_eligibility_fields()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => null,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ];

        $taxonomyPayload = [
            'eligibility_types' => [
                'custom' => [
                    'age_group' => 'custom age group',
                    'disability' => 'custom disability',
                    'ethnicity' => 'custom ethnicity',
                    'gender' => 'custom gender',
                    'income' => 'custom income',
                    'language' => 'custom language',
                    'housing' => 'custom housing',
                    'other' => 'custom other',
                ],
                'taxonomies' => [],
            ],
        ];

        $payload = array_merge($taxonomyPayload, $payload);

        $response = $this->json('POST', '/core/v1/services', $payload);
        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonFragment($taxonomyPayload);
    }

    public function test_create_service_with_custom_fields_and_eligibility_taxonomy_ids()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        $taxonomyIds = Taxonomy::serviceEligibility()
            ->children
            ->map(function ($taxonomy) {
                return $taxonomy
                    ->children()
                    ->inRandomOrder()
                    ->pluck('id')
                    ->first();
            })
            ->toArray();

        sort($taxonomyIds, SORT_STRING);

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'cqc_location_id' => null,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'tags' => [],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ];

        $taxonomyPayload = [
            'eligibility_types' => [
                'custom' => [
                    'age_group' => 'created with custom age group',
                    'disability' => 'created with custom disability',
                    'ethnicity' => 'created with custom ethnicity',
                    'gender' => 'created with custom gender',
                    'income' => 'created with custom income',
                    'language' => 'created with custom language',
                    'housing' => 'created with custom housing',
                    'other' => 'created with custom other',
                ],
                'taxonomies' => $taxonomyIds,
            ],
        ];

        $payload = array_merge($taxonomyPayload, $payload);

        $response = $this->json('POST', '/core/v1/services', $payload);
        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonFragment($taxonomyPayload);
    }

    public function test_update_service_with_eligibility_taxonomies()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        $taxonomyIds = Taxonomy::serviceEligibility()->children->map(function ($taxonomy) {
            // ensure we assign a different taxonomy ID
            return $taxonomy->children()
                ->inRandomOrder()
                ->first()
                ->id;
        })
            ->toArray();

        sort($taxonomyIds, SORT_STRING);

        Passport::actingAs($user);

        $payload = [
            'eligibility_types' => [
                'taxonomies' => $taxonomyIds,
            ],
        ];

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);

        $service->load('serviceEligibilities');

        foreach ($service->serviceEligibilities['taxonomies'] as $taxonomyId) {
            $this->assertTrue(in_array($taxonomyId, $taxonomyIds));
        }
    }

    public function test_update_service_with_custom_eligibility_fields()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        Passport::actingAs($user);

        $payload = [
            'eligibility_types' => [
                'custom' => [
                    'age_group' => 'I am updating custom age_group',
                    'disability' => 'I am updating custom disability',
                    'ethnicity' => 'I am updating custom ethnicity',
                    'gender' => 'I am updating custom gender',
                    'income' => 'I am updating custom income',
                    'language' => 'I am updating custom language',
                    'housing' => 'I am updating custom housing',
                    'other' => 'I am updating custom other',
                ],
            ],
        ];

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);

        // request the service to check update request was auto-applied
        $service = $service->fresh()->load('serviceEligibilities');

        foreach ($payload['eligibility_types']['custom'] as $customFieldName => $customFieldValue) {
            $this->assertEquals($customFieldValue, $service->{'eligibility_' . $customFieldName . '_custom'});
        }
    }

    public function test_update_service_with_custom_fields_and_eligibility_taxonomies()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        $taxonomyIds = Taxonomy::serviceEligibility()->children->map(function ($taxonomy) {
            // ensure we assign a different taxonomy ID
            return $taxonomy->children()
                ->inRandomOrder()
                ->first()
                ->id;
        })
            ->toArray();

        sort($taxonomyIds, SORT_STRING);

        Passport::actingAs($user);

        $payload = [
            'eligibility_types' => [
                'custom' => [
                    'age_group' => 'I am updating custom age_group',
                    'disability' => 'I am updating custom disability',
                    'ethnicity' => 'I am updating custom ethnicity',
                    'gender' => 'I am updating custom gender',
                    'income' => 'I am updating custom income',
                    'language' => 'I am updating custom language',
                    'housing' => 'I am updating custom housing',
                    'other' => 'I am updating custom other',
                ],
                'taxonomies' => $taxonomyIds,
            ],
        ];

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);

        $service = $service->fresh();
        $service->load('serviceEligibilities');

        foreach ($service->serviceEligibilities['taxonomies'] as $taxonomyId) {
            $this->assertTrue(in_array($taxonomyId, $taxonomyIds));
        }

        foreach ($payload['eligibility_types']['custom'] as $customFieldName => $customFieldValue) {
            $this->assertEquals($customFieldValue, $service->{'eligibility_' . $customFieldName . '_custom'});
        }
    }

    public function test_delete_custom_eligibility_fields_from_service()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        Passport::actingAs($user);

        $payload = [
            'eligibility_types' => [
                'custom' => [
                    'age_group' => null,
                    'disability' => null,
                    'ethnicity' => null,
                    'gender' => null,
                    'income' => null,
                    'language' => null,
                    'housing' => null,
                    'other' => null,
                ],
            ],
        ];

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);
    }

    public function test_delete_eligibility_taxonomy_ids_from_service()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        Passport::actingAs($user);

        $payload = [
            'eligibility_types' => [
                'taxonomies' => [],
            ],
        ];
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);
    }

    public function test_delete_eligibility_taxonomy_ids_and_custom_fields_from_service()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        Passport::actingAs($user);

        $payload = [
            'eligibility_types' => [
                'taxonomies' => [],
                'custom' => [
                    'age_group' => null,
                    'disability' => null,
                    'employment' => null,
                    'ethnicity' => null,
                    'gender' => null,
                    'housing' => null,
                    'income' => null,
                    'language' => null,
                    'other' => null,
                ],
            ],
        ];

        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonFragment($payload);
    }

    public function test_eligibility_taxonomy_can_not_be_added_if_top_level_child_of_incorrect_parent_taxonomy()
    {
        $user = factory(User::class)->create()->makeGlobalAdmin();
        $service = factory(Service::class)
            ->states(
                'withOfferings',
                'withUsefulInfo',
                'withSocialMedia',
                'withCustomEligibilities',
                'withEligibilityTaxonomies',
                'withCategoryTaxonomies'
            )
            ->create();

        // When I try to associate a taxonomy that is NOT a child of Service Eligibility
        $incorrectTaxonomyId = Taxonomy::category()->children->random()->id;

        $payload = [
            'eligibility_types' => [
                'taxonomies' => [$incorrectTaxonomyId],
            ],
        ];

        Passport::actingAs($user);

        $response = $this->json('PUT', route('core.v1.services.update', $service->id), $payload);

        // A validation error is thrown
        $response->assertStatus(422);
    }

    public function test_service_update_rejected_if_social_medias_field_is_populated()
    {
        // Given a global admin is logged in
        $globalAdmin = factory(User::class)->create()->makeGlobalAdmin();

        // And a pending update request exists for a service with changes to the social medias
        $service = factory(Service::class)->create([
            'slug' => 'test-service',
            'status' => Service::STATUS_ACTIVE,
        ]);

        $taxonomy = Taxonomy::category()->children()->firstOrFail();
        $service->syncTaxonomyRelationships(new Collection([$taxonomy]));
        $serviceAdmin = factory(User::class)->create()->makeServiceAdmin($service);

        Passport::actingAs($serviceAdmin);
        $payload = [
            'social_medias' => [
                [
                    'type' => SocialMedia::TYPE_FACEBOOK,
                    'url' => 'https://www.facebook.com/randomPerson',
                ],
            ],
        ];

        // Create the update request as service admin
        $response = $this->json('PUT', "/core/v1/services/{$service->id}", $payload);
        $response->assertStatus(422);
        $response->assertJsonFragment(['social_medias' => ['This field is no longer accepted for services and should be set in the Organisation.']]);
    }

    public function test_service_creation_rejected_if_social_medias_field_is_populated()
    {
        $organisation = factory(Organisation::class)->create();
        $user = factory(User::class)->create()->makeGlobalAdmin();

        Passport::actingAs($user);

        $payload = [
            'organisation_id' => $organisation->id,
            'slug' => 'test-service',
            'name' => 'Test Service',
            'type' => Service::TYPE_SERVICE,
            'status' => Service::STATUS_ACTIVE,
            'intro' => 'This is a test intro',
            'description' => 'Lorem ipsum',
            'wait_time' => null,
            'is_free' => true,
            'fees_text' => null,
            'fees_url' => null,
            'testimonial' => null,
            'video_embed' => null,
            'url' => $this->faker->url,
            'contact_name' => $this->faker->name,
            'contact_phone' => random_uk_phone(),
            'contact_email' => $this->faker->safeEmail,
            'show_referral_disclaimer' => false,
            'referral_method' => Service::REFERRAL_METHOD_NONE,
            'referral_button_text' => null,
            'referral_email' => null,
            'referral_url' => null,
            'useful_infos' => [
                [
                    'title' => 'Did you know?',
                    'description' => 'Lorem ipsum',
                    'order' => 1,
                ],
            ],
            'offerings' => [
                [
                    'offering' => 'Weekly club',
                    'order' => 1,
                ],
            ],
            'social_medias' => [
                [
                    'type' => SocialMedia::TYPE_INSTAGRAM,
                    'url' => 'https://www.instagram.com/ayupdigital',
                ],
            ],
            'gallery_items' => [],
            'category_taxonomies' => [Taxonomy::category()->children()->firstOrFail()->id],
        ];
        $response = $this->json('POST', '/core/v1/services', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment(['social_medias' => ['This field is no longer accepted for services and should be set in the Organisation.']]);

    }

    public function test_service_update_request_approval_rejected_if_social_medias_field_is_populated()
    {
        $now = Date::now();
        Date::setTestNow($now);

        $user = factory(User::class)->create()->makeGlobalAdmin();
        Passport::actingAs($user);

        $service = factory(Service::class)->create();
        $service->serviceTaxonomies()->create([
            'taxonomy_id' => Taxonomy::category()->children()->firstOrFail()->id,
        ]);
        $updateRequest = $service->updateRequests()->create([
            'user_id' => factory(User::class)->create()->id,
            'data' => [
                'slug' => $service->slug,
                'name' => 'Test Name',
                'type' => $service->type,
                'status' => $service->status,
                'intro' => $service->intro,
                'description' => $service->description,
                'wait_time' => $service->wait_time,
                'is_free' => $service->is_free,
                'fees_text' => $service->fees_text,
                'fees_url' => $service->fees_url,
                'testimonial' => $service->testimonial,
                'video_embed' => $service->video_embed,
                'url' => $service->url,
                'contact_name' => $service->contact_name,
                'contact_phone' => $service->contact_phone,
                'contact_email' => $service->contact_email,
                'show_referral_disclaimer' => $service->show_referral_disclaimer,
                'referral_method' => $service->referral_method,
                'referral_button_text' => $service->referral_button_text,
                'referral_email' => $service->referral_email,
                'referral_url' => $service->referral_url,
                'cqc_location_id' => $service->cqc_location_id,
                'useful_infos' => [],
                'social_medias' => [
                    [
                        'type' => SocialMedia::TYPE_INSTAGRAM,
                        'url' => 'https://www.instagram.com/ayupdigital/',
                    ],
                ],
                'category_taxonomies' => $service->taxonomies()->pluck('taxonomies.id')->toArray(),
            ],
        ]);

        $response = $this->json('PUT', "/core/v1/update-requests/{$updateRequest->id}/approve");

        $response->assertStatus(422);
        $response->assertJsonFragment(['social_medias' => ['This field is no longer accepted for services and should be set in the Organisation.']]);

    }
}
