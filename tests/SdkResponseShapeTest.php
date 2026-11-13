<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Support\Pages;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use SensitiveParameterValue;
use stdClass;

class SdkResponseShapeTest extends TestCase
{
    public function test_opt_in_constructs_both_sdk_clients_without_sending_http(): void
    {
        config(['airwallex.preserve_response_objects' => true, 'airwallex.payments.account_id' => 'account-payments',
            'airwallex.payments.client_id' => 'client-synthetic', 'airwallex.payments.api_key' => 'key-synthetic']);
        $http = new FakeHttpClient;
        $this->app->instance(ClientInterface::class, $http);
        foreach ([Client::class, Payments\Client::class] as $class) {
            self::assertInstanceOf($class, app($class));
        }
        self::assertSame([], $http->requests);
    }

    public function test_non_boolean_configuration_fails_before_http(): void
    {
        config(['airwallex.preserve_response_objects' => 'yes']);
        $this->expectException(ConfigurationException::class);
        app(Client::class);
    }

    public function test_configuration_failure_redacts_the_decoded_event_from_exception_trace_arguments(): void
    {
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (['invalid'] as $setting) {
                config(['airwallex.preserve_response_objects' => $setting]);
                $event = WebhookEvent::fromJson('{"id":"synthetic-event","profile":{"email":"private-synthetic@example.test"}}');
                try {
                    SdkResponseShape::webhook($event);
                    self::fail('Expected configuration rejection.');
                } catch (ConfigurationException $exception) {
                    $frames = array_values(array_filter($exception->getTrace(), fn (array $frame): bool => ($frame['class'] ?? null) === SdkResponseShape::class && $frame['function'] === 'webhook'));
                    self::assertCount(1, $frames);
                    self::assertInstanceOf(SensitiveParameterValue::class, $frames[0]['args'][0]);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $original);
        }
    }

    public function test_selected_sdk_http_pages_preserve_metadata_objects_and_array_interfaces_in_both_families(): void
    {
        $this->enableObjects();
        config(['airwallex.payments.account_id' => 'account-payments', 'airwallex.payments.client_id' => 'client-synthetic', 'airwallex.payments.api_key' => 'key-synthetic']);
        $item = ['id' => 'customer-one', 'metadata' => (object) ['0' => 'zero'], 'extra' => ['empty' => new stdClass, 'list' => ['zero']], 'amount' => '10.000000000001'];
        $http = new FakeHttpClient([FakeHttpClient::login(), FakeHttpClient::json(['items' => [$item], 'page_after' => null]),
            FakeHttpClient::login(), FakeHttpClient::json(['items' => [$item], 'has_more' => false])]);
        $this->app->instance(ClientInterface::class, $http);
        $billing = Pages::all(fn (array $query) => app(Client::class)->billing()->customers()->list($query));
        $payments = SdkResponseShape::normalize(app(Payments\Client::class)->sdk->payments()->customers()->list());
        foreach ([$billing[0], $payments['items'][0]] as $customer) {
            self::assertSame('customer-one', $customer['id']);
            self::assertInstanceOf(stdClass::class, $customer['metadata']);
            self::assertSame('{"0":"zero"}', json_encode($customer['metadata']));
            self::assertInstanceOf(stdClass::class, $customer['extra']['empty']);
            self::assertSame(['zero'], $customer['extra']['list']);
            self::assertSame('10.000000000001', $customer['amount']);
        }
        self::assertCount(4, $http->requests);
    }

    public function test_signed_wrapped_and_direct_envelopes_use_the_selected_sdk_view(): void
    {
        $this->enableObjects();
        $verifier = new WebhookVerifier('synthetic-secret', 0, static fn (): int => 1700000000000);
        foreach (['{"object":{"id":"one","metadata":{"0":"zero"},"empty":{},"list":[]}}', '{"id":"one","metadata":{"0":"zero"},"empty":{},"list":[]}'] as $data) {
            $body = '{"id":"event-one","name":"future.event","data":'.$data.'}';
            $timestamp = '1700000000000';
            $verifier->verify($body, $timestamp, hash_hmac('sha256', $timestamp.$body, 'synthetic-secret'));
            $event = SdkResponseShape::webhook(WebhookEvent::fromJson($body));
            $object = $event['data']['object'] ?? $event['data'];
            self::assertSame('one', $object['id']);
            self::assertSame('{"0":"zero"}', json_encode($object['metadata']));
            self::assertInstanceOf(stdClass::class, $object['metadata']);
            self::assertInstanceOf(stdClass::class, $object['empty']);
            self::assertSame([], $object['list']);
        }
    }

    private function enableObjects(): void
    {
        config(['airwallex.preserve_response_objects' => true]);
    }
}
