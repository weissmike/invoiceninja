<?php

namespace Tests\Feature;

use App\DataMapper\ClientSettings;
use App\DataMapper\CompanySettings;
use App\Factory\ClientFactory;
use App\Factory\CreditFactory;
use App\Factory\InvoiceFactory;
use App\Factory\PaymentFactory;
use App\Helpers\Invoice\InvoiceSum;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Utils\Traits\MakesHash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * @test
 * @covers App\Utils\Traits\Payment\Refundable
 */
    
class RefundTest extends TestCase
{
    use MakesHash;
    use DatabaseTransactions;
    use MockAccountData;

    public function setUp() :void
    {
        parent::setUp();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );

        Session::start();

        $this->faker = \Faker\Factory::create();

        Model::reguard();

        $this->makeTestData();

        $this->withoutExceptionHandling();
    }

    /**
     * Test that a simple payment of $50
     * is able to be refunded.
     */
    public function testBasicRefundValidation()
    {
        $client = ClientFactory::create($this->company->id, $this->user->id);
        $client->save();

        $this->invoice = InvoiceFactory::create($this->company->id, $this->user->id);//stub the company and user_id
        $this->invoice->client_id = $client->id;
        $this->invoice->status_id = Invoice::STATUS_SENT;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_Taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();
        $this->invoice->save();

        $data = [
            'amount' => 50,
            'client_id' => $client->hashed_id,
            'date' => '2020/12/12',

        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments', $data);

        
        $arr = $response->json();
        $response->assertStatus(200);

        $payment_id = $arr['data']['id'];
        
        $this->assertEquals(50, $arr['data']['amount']);

        $payment = Payment::whereId($this->decodePrimaryKey($payment_id))->first();

        $this->assertNotNull($payment);

        $data = [
            'id' => $this->encodePrimaryKey($payment->id),
            'amount' => 50,
            'date' => '2020/12/12',
        ];

        $response = false;

        try {
            $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments/refund', $data);
        } catch (ValidationException $e) {
            $message = json_decode($e->validator->getMessageBag(), 1);

            \Log::error($message);
        }

        $arr = $response->json();

        $response->assertStatus(200);

        $this->assertEquals(50, $arr['data']['refunded']);
        $this->assertEquals(Payment::STATUS_REFUNDED, $arr['data']['status_id']);
    }

    /**
     * Test that a payment with Invoices
     * requires a refund with invoices specified.
     *
     * Should produce a validation error if
     * no invoices are specified in the refund
     */
    public function testRefundValidationNoInvoicesProvided()
    {
        $client = ClientFactory::create($this->company->id, $this->user->id);
        $client->save();

        $this->invoice = InvoiceFactory::create($this->company->id, $this->user->id);//stub the company and user_id
        $this->invoice->client_id = $client->id;
        $this->invoice->status_id = Invoice::STATUS_SENT;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_Taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();
        $this->invoice->save();

        $data = [
            'amount' => 50,
            'client_id' => $client->hashed_id,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'date' => '2020/12/12',

        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments', $data);

        
        $arr = $response->json();
        $response->assertStatus(200);

        $payment_id = $arr['data']['id'];

        $this->assertEquals(50, $arr['data']['amount']);

        $payment = Payment::whereId($this->decodePrimaryKey($payment_id))->first();

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->invoices());
        $this->assertEquals(1, $payment->invoices()->count());
        

        $data = [
            'id' => $this->encodePrimaryKey($payment->id),
            'amount' => 50,
            'date' => '2020/12/12',
        ];

        $response = false;

        try {
            $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments/refund', $data);
        } catch (ValidationException $e) {
            $message = json_decode($e->validator->getMessageBag(), 1);

            $this->assertNotNull($message);
            \Log::error($message);
        }

        if ($response) {
            $response->assertStatus(302);
        }
    }

    /**
     * Test that a refund with invoices provided
     * passes.
     */
    public function testRefundValidationWithValidInvoiceProvided()
    {
        $client = ClientFactory::create($this->company->id, $this->user->id);
        $client->save();

        $this->invoice = InvoiceFactory::create($this->company->id, $this->user->id);//stub the company and user_id
        $this->invoice->client_id = $client->id;
        $this->invoice->status_id = Invoice::STATUS_SENT;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();
        $this->invoice->save();

        $data = [
            'amount' => 50,
            'client_id' => $client->hashed_id,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'date' => '2020/12/12',

        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments', $data);

        
        $arr = $response->json();
        $response->assertStatus(200);

        $payment_id = $arr['data']['id'];

        $this->assertEquals(50, $arr['data']['amount']);

        $payment = Payment::whereId($this->decodePrimaryKey($payment_id))->first();

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->invoices());
        $this->assertEquals(1, $payment->invoices()->count());
        

        $data = [
            'id' => $this->encodePrimaryKey($payment->id),
            'amount' => 50,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'date' => '2020/12/12',
        ];

        $response = false;

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments/refund', $data);
        
        $response->assertStatus(200);
    }

    /**
     * Test Validation with incorrect invoice refund amounts
     */
    public function testRefundValidationWithInValidInvoiceRefundedAmount()
    {
        $client = ClientFactory::create($this->company->id, $this->user->id);
        $client->save();

        $this->invoice = InvoiceFactory::create($this->company->id, $this->user->id);//stub the company and user_id
        $this->invoice->client_id = $client->id;
        $this->invoice->status_id = Invoice::STATUS_SENT;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();
        $this->invoice->save();

        $data = [
            'amount' => 50,
            'client_id' => $client->hashed_id,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'date' => '2020/12/12',

        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments', $data);

        
        $arr = $response->json();
        $response->assertStatus(200);

        $payment_id = $arr['data']['id'];

        $this->assertEquals(50, $arr['data']['amount']);

        $payment = Payment::whereId($this->decodePrimaryKey($payment_id))->first();

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->invoices());
        $this->assertEquals(1, $payment->invoices()->count());
        

        $data = [
            'id' => $this->encodePrimaryKey($payment->id),
            'amount' => 50,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => 100
                ],
            ],
            'date' => '2020/12/12',
        ];

        $response = false;

        try {
            $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments/refund', $data);
        } catch (ValidationException $e) {
            $message = json_decode($e->validator->getMessageBag(), 1);

            \Log::error($message);
        }

        if ($response) {
            $response->assertStatus(302);
        }
    }
    
    /**
     * Tests refund when providing an invoice
     * not related to the payment
     */
    public function testRefundValidationWithInValidInvoiceProvided()
    {
        $client = ClientFactory::create($this->company->id, $this->user->id);
        $client->save();

        $this->invoice = InvoiceFactory::create($this->company->id, $this->user->id);//stub the company and user_id
        $this->invoice->client_id = $client->id;
        $this->invoice->status_id = Invoice::STATUS_SENT;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();
        $this->invoice->save();

        $data = [
            'amount' => 50,
            'client_id' => $client->hashed_id,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'date' => '2020/12/12',

        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments', $data);

        
        $arr = $response->json();
        $response->assertStatus(200);

        $payment_id = $arr['data']['id'];

        $this->assertEquals(50, $arr['data']['amount']);

        $payment = Payment::whereId($this->decodePrimaryKey($payment_id))->first();

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->invoices());
        $this->assertEquals(1, $payment->invoices()->count());
        
        $this->invoice = InvoiceFactory::create($this->company->id, $this->user->id);//stub the company and user_id
        $this->invoice->client_id = $client->id;
        $this->invoice->status_id = Invoice::STATUS_SENT;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();
        $this->invoice->save();

        $data = [
            'id' => $this->encodePrimaryKey($payment->id),
            'amount' => 50,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'date' => '2020/12/12',
        ];

        $response = false;

        try {
            $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments/refund', $data);
        } catch (ValidationException $e) {
            $message = json_decode($e->validator->getMessageBag(), 1);

            \Log::error($message);
        }

        if ($response) {
            $response->assertStatus(302);
        }
    }

    /**
     * Test refunds where payments include credits
     *
     * $10 invoice
     * $10 credit
     * $50 credit card payment
     *
     *
     * result should be
     *
     * payment.applied = 10
     * credit.balance = 0
     *
     */
    public function testRefundWhereCreditsArePresent()
    {
        $client = ClientFactory::create($this->company->id, $this->user->id);
        $client->save();

        $this->invoice = InvoiceFactory::create($this->company->id, $this->user->id);//stub the company and user_id
        $this->invoice->client_id = $client->id;
        $this->invoice->status_id = Invoice::STATUS_SENT;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();
        $this->invoice->save();

        $this->credit = CreditFactory::create($this->company->id, $this->user->id);
        $this->credit->client_id = $this->client->id;

        $this->credit->line_items = $this->buildLineItems();
        $this->credit->amount = 10;
        $this->credit->balance = 10;

        $this->credit->uses_inclusive_taxes = false;
        $this->credit->save();

        $data = [
            'amount' => 50,
            'client_id' => $client->hashed_id,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'credits' => [
                [
                'credit_id' => $this->credit->hashed_id,
                'amount' => $this->credit->amount
                ],
            ],
            'date' => '2020/12/12',

        ];

        $response = false;

        try {
            $response = $this->withHeaders([
                'X-API-SECRET' => config('ninja.api_secret'),
                'X-API-TOKEN' => $this->token,
            ])->post('/api/v1/payments', $data);
        } catch (ValidationException $e) {
            $message = json_decode($e->validator->getMessageBag(), 1);
            \Log::error($message);
        }
        
        $arr = $response->json();
        $response->assertStatus(200);

        $payment_id = $arr['data']['id'];

        $this->assertEquals(50, $arr['data']['amount']);

        $payment = Payment::whereId($this->decodePrimaryKey($payment_id))->first();

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->invoices());
        $this->assertEquals(1, $payment->invoices()->count());
        

        $data = [
            'id' => $this->encodePrimaryKey($payment->id),
            'amount' => 50,
            'invoices' => [
                [
                'invoice_id' => $this->invoice->hashed_id,
                'amount' => $this->invoice->amount
                ],
            ],
            'date' => '2020/12/12',
        ];

        $response = false;

        try {
            $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/payments/refund', $data);
        } catch (ValidationException $e) {
            $message = json_decode($e->validator->getMessageBag(), 1);
            \Log::error("refund message error");
            \Log::error($message);
        }


        $response->assertStatus(200);
        $arr = $response->json();

        $payment = Payment::find($this->decodePrimaryKey($arr['data']['id']));

//            \Log::error(print_r($payment->paymentables->toArray(),1));
    }

    /*Additional scenarios*/
}
