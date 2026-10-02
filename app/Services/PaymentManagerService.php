<?php

namespace App\Services;


class PaymentManagerService
{

    public object $gateway;

    public function gateway(...$args) : static
    {
        $paymentMethod = '';
        if (count($args) > 0) {
            $paymentMethod = (string) array_shift($args);
        }

        if (count($args) == 0) {
            $args = null;
        }

        $className     = self::gatewayClass($paymentMethod);
        $this->gateway = new $className($args);
        return $this;
    }

    /**
     * The gateway class for a name that arrives in a URL or a form field.
     *
     * The name is concatenated into a class name, so it must be a plain word
     * naming a real gateway; anything else - an unknown name, a namespace
     * fragment - is refused here instead of surfacing as a "class not found"
     * 500 from deep inside a payment request.
     */
    public static function gatewayClass(string $name): string
    {
        $className = 'App\\Http\\PaymentGateways\\Gateways\\' . ucfirst($name);

        if (!preg_match('/^[A-Za-z0-9]+$/', $name) || !class_exists($className) || !is_subclass_of($className, PaymentAbstract::class)) {
            throw new \InvalidArgumentException('Unknown payment gateway.');
        }

        return $className;
    }

    public function payment($order, $request)
    {
        return $this->gateway->payment($order, $request);
    }

    public function status()
    {
        return $this->gateway->status();
    }

    public function success($order, $request)
    {
        return $this->gateway->success($order, $request);
    }

    public function fail($order, $request)
    {
        return $this->gateway->fail($order, $request);
    }

    public function cancel($order, $request)
    {
        return $this->gateway->cancel($order, $request);
    }

}
