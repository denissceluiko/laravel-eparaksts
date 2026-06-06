<?php

namespace Dencel\LaravelEparaksts\Tests\Controllers;

use Dencel\Eparaksts\Eparaksts;
use Dencel\LaravelEparaksts\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

class TestUser extends Authenticatable
{
    protected $guarded = [];
    public $timestamps = false;
    protected $table   = 'users';
}

#[CoversNothing]
class RegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('eparaksts.user_model', TestUser::class);
        $app['config']->set('eparaksts.registration_enabled', true);
        $app['config']->set('eparaksts.authentication_match', ['personal_number']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('personal_number')->nullable();
        });
    }

    private function blob(array $data): string
    {
        return base64_encode(json_encode($data));
    }

    private function epSession(array $storage = [], array $extra = []): array
    {
        return array_merge(
            ['eparaksts__ep_storage' => $this->blob($storage)],
            $extra
        );
    }

    public function testRegistrationEnabled(): void
    {
        $state = 'reg-state';

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'ident-tok',
                'expires_in'   => 3600,
                'scope'        => Eparaksts::SCOPE_IDENTIFICATION,
            ])),
            new Response(200, [], json_encode([
                'name'          => 'JĀNIS BĒRZIŅŠ',
                'given_name'    => 'JĀNIS',
                'family_name'   => 'BĒRZIŅŠ',
                'serial_number' => 'PNOLV-123456-12345',
            ])),
        ]));
        $this->app->instance('eparaksts-connector', new Eparaksts('client', 'secret', handlerStack: $stack));

        $this->withSession($this->epSession([
            'state'     => $state,
            'action'    => Eparaksts::SCOPE_IDENTIFICATION,
            'tokens'    => [],
            'callbacks' => [],
        ]))
            ->get('/eparaksts/callback?code=auth-code&state=' . $state)
            ->assertRedirect('/');

        $this->assertDatabaseHas('users', ['personal_number' => 'PNOLV-123456-12345']);
    }
}
