<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Payments\Models\Receipt;
use Jojeringnax\AirwallexLaravel\Payments\Receipts;

// Exact received bytes and headers. SDK verification precedes durable acceptance and canonical queued processing.
return static fn (string $rawBody, string $timestamp, string $signature): Receipt => app(Receipts::class)->accept($rawBody, $timestamp, $signature);
