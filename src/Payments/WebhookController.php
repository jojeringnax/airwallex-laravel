<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use Throwable;

class WebhookController
{
    public function __invoke(Request $request): Response
    {
        $timestamps = $request->headers->all('x-timestamp');
        $signatures = $request->headers->all('x-signature');
        if (count($timestamps) !== 1 || count($signatures) !== 1) {
            return new Response('Invalid Payments webhook delivery.', 400);
        }
        try {
            $receipts = app(Receipts::class);
            $receipt = $receipts->accept($request->getContent(), $timestamps[0], $signatures[0]);
        } catch (WebhookVerificationException|ProtocolException) {
            return new Response('Invalid Payments webhook delivery.', 400);
        } catch (Throwable) {
            return new Response('Payments webhook acceptance unavailable.', 503);
        }
        try {
            $receipts->dispatch($receipt);
        } catch (Throwable) {
            // Acceptance already committed; recovery finds the durable pending row.
        }

        return new Response('', 200);
    }
}
