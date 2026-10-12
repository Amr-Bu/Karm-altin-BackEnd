<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmployeeCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateEmployeeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run php vendor/bin/phpunit -c phpunit.mysql.xml for MySQL-backed API tests.');
        }
        $this->admin = User::create(['name' => 'Test Admin', 'username' => 'admin_'.bin2hex(random_bytes(8)),
            'password_hash' => Hash::make('test-password'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->withToken($this->admin->createToken('employee-tests')->plainTextToken);
    }

    private function body(): array
    {
        return ['name' => 'Ahmad Ali', 'username' => 'employee_'.bin2hex(random_bytes(8)),
            'password' => 'StrongPassword123', 'role' => User::ROLE_EMPLOYEE];
    }

    public static function roles(): array
    {
        return [[User::ROLE_EMPLOYEE], [User::ROLE_ADMIN]];
    }

    #[DataProvider('roles')]
    public function test_admin_creates_user_with_hashed_password_and_public_response(string $role): void
    {
        $body = array_replace($this->body(), ['role' => $role]);
        $response = $this->postJson('/api/employees', $body)->assertCreated();
        $user = User::where('username', $body['username'])->firstOrFail();
        $response->assertExactJson(['data' => ['id' => $user->id, 'name' => $body['name'],
            'username' => $body['username'], 'role' => $role, 'is_active' => true]]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $body['username'], 'role' => $role, 'is_active' => 1]);
        $this->assertNotSame($body['password'], $user->password_hash);
        $this->assertTrue(Hash::check($body['password'], $user->password_hash));
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/login', ['username' => $body['username'], 'password' => $body['password']])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonStructure(['token']);
    }

    public function test_duplicate_username_is_rejected(): void
    {
        $before = User::count();
        $this->postJson('/api/employees', array_replace($this->body(), ['username' => $this->admin->username]))
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame($before, User::count());
    }

    public static function requiredFields(): array
    {
        return [['name'], ['username'], ['password'], ['role']];
    }

    #[DataProvider('requiredFields')]
    public function test_required_fields(string $field): void
    {
        $body = $this->body();
        unset($body[$field]);
        $before = User::count();
        $this->postJson('/api/employees', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, User::count());
    }

    public static function invalidFields(): array
    {
        return [
            ['name', str_repeat('a', 101)], ['username', str_repeat('a', 101)],
            ['password', 'short'], ['password', str_repeat('a', 73)], ['password', str_repeat('ع', 37)],
            ['password', "password\0invalid"], ['password', ['not a string']],
            ['role', 'MANAGER'], ['role', 'admin'], ['role', ['ADMIN']],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_fields(string $field, mixed $value): void
    {
        $before = User::count();
        $this->postJson('/api/employees', array_replace($this->body(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, User::count());
    }

    public static function protectedFields(): array
    {
        return [['is_active', false], ['is_active', null], ['id', 123], ['password_hash', 'injected'],
            ['created_at', '2000-01-01'], ['updated_at', '2000-01-01'], ['remember_token', 'secret'],
            ['token', 'secret'], ['tokens', []]];
    }

    #[DataProvider('protectedFields')]
    public function test_protected_fields_are_rejected(string $field, mixed $value): void
    {
        $before = User::count();
        $this->postJson('/api/employees', $this->body() + [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, User::count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $before = User::count();
        $this->withHeader('Authorization', '')->postJson('/api/employees', $this->body())->assertUnauthorized();
        $this->assertSame($before, User::count());
    }

    public function test_employee_is_forbidden(): void
    {
        $this->admin->update(['role' => User::ROLE_EMPLOYEE]);
        $before = User::count();
        $this->postJson('/api/employees', $this->body())->assertForbidden();
        $this->assertSame($before, User::count());
    }

    public function test_inactive_admin_with_existing_token_is_forbidden(): void
    {
        $this->admin->update(['is_active' => false]);
        $before = User::count();
        $this->postJson('/api/employees', $this->body())->assertForbidden();
        $this->assertSame($before, User::count());
    }

    public function test_database_unique_constraint_race_is_mapped_to_validation(): void
    {
        $body = array_replace($this->body(), ['username' => $this->admin->username]);
        try {
            $this->app->make(EmployeeCreationService::class)->create($body);
            $this->fail('The duplicate insert should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('username', $exception->errors());
            $this->assertSame(422, $exception->status);
        }
    }
}
