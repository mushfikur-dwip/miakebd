<?php

namespace App\Http\SmsGateways\Gateways;

use App\Enums\Activity;
use App\Models\SmsGateway;
use App\Services\SmsAbstract;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class Bulksmsbd extends SmsAbstract
{
    public string $apiKey;

    public string $senderId;

    public string $baseUrl;

    public function __construct()
    {
        parent::__construct();
        $this->smsGateway = SmsGateway::with('gatewayOptions')->where(['slug' => 'bulksmsbd'])->first();
        if (! blank($this->smsGateway)) {
            $this->smsGatewayOption = $this->smsGateway->gatewayOptions->pluck('value', 'option');
            $this->gateway = new Client([
                'connect_timeout' => 10,
                'timeout' => 20,
                'verify' => true,
            ]);
            $this->baseUrl = 'https://bulksmsbd.net/api/smsapi';
            $this->apiKey = (string) $this->smsGatewayOption['bulksmsbd_api_key'];
            $this->senderId = (string) $this->smsGatewayOption['bulksmsbd_sender_id'];
        }
    }

    public function status(): bool
    {
        $paymentGateways = SmsGateway::where(['slug' => 'bulksmsbd', 'status' => Activity::ENABLE])->first();

        return (bool) $paymentGateways;
    }

    public function send($code, $phone, $message): void
    {
        try {
            $number = $this->normalizeBangladeshNumber((string) $code, (string) $phone);
            $response = $this->gateway->post($this->baseUrl, [
                'form_params' => [
                    'api_key' => $this->apiKey,
                    'type' => 'text',
                    'number' => $number,
                    'senderid' => $this->senderId,
                    'message' => (string) $message,
                ],
            ]);
            $body = trim((string) $response->getBody());

            Log::info('BulkSMSBD SMS request completed.', [
                'number_suffix' => substr($number, -4),
                'http_status' => $response->getStatusCode(),
                'response' => $body,
            ]);
        } catch (GuzzleException|Exception $exception) {
            Log::error('BulkSMSBD SMS request failed.', [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function normalizeBangladeshNumber(string $countryCode, string $phone): string
    {
        $countryCode = ltrim(preg_replace('/\D+/', '', $countryCode), '0');
        $phone = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($phone, '880')) {
            return $phone;
        }

        $phone = ltrim($phone, '0');

        if ($countryCode === '') {
            $countryCode = '880';
        }

        return $countryCode.$phone;
    }
}
