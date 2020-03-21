<?php

namespace Tests\Feature;

use App\DataMapper\DefaultSettings;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Design;
use App\Models\User;
use App\Utils\Traits\MakesHash;
use Faker\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * @test
 * @covers App\Http\Controllers\DesignController
 */
class DesignApiTest extends TestCase
{
    use MakesHash;
    use DatabaseTransactions;
    use MockAccountData;

    public $id;

    public function setUp() :void
    {
        parent::setUp();

        $this->makeTestData();

        Session::start();

        $this->faker = \Faker\Factory::create();

        Model::reguard();
    }


    public function testDesignPost()
    {
        $design = [
            'body' => 'body',
            'includes' => 'includes',
            'product' => 'product',
            'task' => 'task',
            'footer' => 'footer',
            'header' => 'header'
        ];

        $data = [
            'name' => $this->faker->firstName,
            'design' => $design
        ];


        $response = $this->withHeaders([
                'X-API-SECRET' => config('ninja.api_secret'),
                'X-API-TOKEN' => $this->token
            ])->post('/api/v1/designs', $data);


        $response->assertStatus(200);

        $arr = $response->json();

        $this->id = $arr['data']['id'];

        $this->assertEquals($data['name'], $arr['data']['name']);

        $response = $this->withHeaders([
                'X-API-SECRET' => config('ninja.api_secret'),
                'X-API-TOKEN' => $this->token
            ])->get('/api/v1/designs');

        $response->assertStatus(200);

        $arr = $response->json();

        $response = $this->withHeaders([
                'X-API-SECRET' => config('ninja.api_secret'),
                'X-API-TOKEN' => $this->token
            ])->get('/api/v1/designs/'.$this->id);

        $response->assertStatus(200);

        $arr = $response->json();

        $this->assertEquals($this->id, $arr['data']['id']);

        $data = [
            'name' => $this->faker->firstName,
            'design' => $design
        ];


        $response = $this->withHeaders([
                'X-API-SECRET' => config('ninja.api_secret'),
                'X-API-TOKEN' => $this->token
            ])->put('/api/v1/designs/'.$this->id, $data);

        $response->assertStatus(200);

        $arr = $response->json();

        $this->assertEquals($data['name'], $arr['data']['name']);
        $this->assertEquals($data['design'], $arr['data']['design']);


        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token
        ])->delete('/api/v1/designs/'.$this->id, $data);


        $response->assertStatus(200);

        $arr = $response->json();

        $design = Design::whereId($this->decodePrimaryKey($this->id))->withTrashed()->first();

        $this->assertTrue((bool)$design->is_deleted);
        $this->assertGreaterThan(0, $design->deleted_at);
    }
}
