<?php
namespace WaldorfshopSeoTest\Services;

use IO\Services\BasketService;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Order\Shipping\Events\AfterShippingCostCalculated;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\ConfigRepository;

/** The fee participates in Plenty's shipping, VAT and payment calculation. */
class ClimateContributionService
{
    const AMOUNT = 0.50;
    const CONSENT_KEY = 'WaldorfshopSeoTest.climateConsent.v1';

    private $session;
    private $baskets;
    private $checkout;
    private $config;

    public function __construct(
        SessionStorageRepositoryContract $session,
        BasketRepositoryContract $baskets,
        Checkout $checkout,
        ConfigRepository $config
    ) {
        $this->session = $session;
        $this->baskets = $baskets;
        $this->checkout = $checkout;
        $this->config = $config;
    }

    public function enabled(): bool
    {
        return $this->config->get('WaldorfshopSeoTest.climate.enabled', 'false') === 'true';
    }

    public function eligible($basket): bool
    {
        return $this->enabled() && $basket && $basket->id > 0
            && $basket->currency === 'EUR' && !$basket->orderId
            && count($basket->basketItems) > 0;
    }

    public function selected($basket): bool
    {
        $consent = $this->session->getSessionValue(self::CONSENT_KEY);
        return $this->eligible($basket) && is_array($consent)
            && (int)$consent['basketId'] === (int)$basket->id
            && $consent['accepted'] === true;
    }

    public function apply(AfterShippingCostCalculated $event)
    {
        // This hook contributes a fee to the actual shipping-cost calculation.
        // Shipment/backend recalculations of existing orders must not use basket consent.
        if ($event->getOrderId() > 0) {
            return;
        }
        $basket = $this->baskets->load();
        if ($this->selected($basket)) {
            $fee = self::AMOUNT;
            $order = $this->session->getOrder();
            $basketService = pluginApp(BasketService::class);
            // IO displays net totals only for net orders without collected VAT.
            // The shipping event takes gross fees, which Plenty converts to net.
            // Use the same maximum basket VAT rate as the shipping calculation.
            if ($order && $order->isNet && count($basketService->getTotalVats()) === 0) {
                $fee *= $this->netFeeFactor($basket);
            }
            $event->addAdditionalFee($fee);
        }
    }

    private function netFeeFactor($basket): float
    {
        // Export item VAT can become zero after a reload although the shipping
        // calculator still removes the source VAT. Read its gross/net amounts.
        if ($basket->shippingAmount > 0 && $basket->shippingAmountNet > 0) {
            return $basket->shippingAmount / $basket->shippingAmountNet;
        }
        $consent = $this->session->getSessionValue(self::CONSENT_KEY);
        if (is_array($consent) && (int)$consent['basketId'] === (int)$basket->id
            && isset($consent['netFeeFactor'])) {
            return (float)$consent['netFeeFactor'];
        }
        return 1 + max(0, pluginApp(BasketService::class)->getMaxVatValue()) / 100;
    }

    public function state(): array
    {
        $basket = $this->baskets->load();
        if (!$this->eligible($basket)) {
            $this->session->setSessionValue(self::CONSENT_KEY, null);
        }
        return [
            'enabled' => $this->enabled(),
            'eligible' => $this->eligible($basket),
            'selected' => $this->selected($basket),
            'amount' => self::AMOUNT,
            'basketId' => $basket ? (int)$basket->id : 0,
            'basket' => pluginApp(BasketService::class)->getBasketForTemplate()
        ];
    }

    public function setSelected(bool $selected, int $basketId): array
    {
        $basket = $this->baskets->load();
        if (!$basket || (int)$basket->id !== $basketId || !$this->eligible($basket)) {
            throw new \RuntimeException('Der Warenkorb hat sich geändert. Bitte lade die Kasse neu.');
        }
        if ($this->selected($basket) === $selected) {
            return $this->state();
        }

        $previous = $this->session->getSessionValue(self::CONSENT_KEY);
        $before = pluginApp(BasketService::class)->getBasketForTemplate();
        $netFeeFactor = $this->netFeeFactor($basket);
        $this->session->setSessionValue(self::CONSENT_KEY, [
            'basketId' => $basketId,
            'accepted' => $selected,
            'amount' => self::AMOUNT,
            'netFeeFactor' => $netFeeFactor,
            'acceptedAt' => $selected ? time() : null,
            'textVersion' => '2026-10-09'
        ]);

        try {
            $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
            $after = $this->state();
            $expectedCents = $selected ? 50 : -50;
            $difference = (int)round(($after['basket']['basketAmount'] - $before['basketAmount']) * 100);
            // Zero-cost shipping offers no initial gross/net ratio. Calibrate
            // once from Plenty's actual result, then keep the exact-delta guard.
            $order = $this->session->getOrder();
            if ($selected && $difference > 0 && $difference < 50 && $order && $order->isNet
                && count(pluginApp(BasketService::class)->getTotalVats()) === 0) {
                $factor = $netFeeFactor * 50 / $difference;
                if ($factor >= 1 && $factor <= 1.5) {
                    $consent = $this->session->getSessionValue(self::CONSENT_KEY);
                    $consent['netFeeFactor'] = $factor;
                    $this->session->setSessionValue(self::CONSENT_KEY, $consent);
                    $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
                    $after = $this->state();
                    $difference = (int)round(($after['basket']['basketAmount'] - $before['basketAmount']) * 100);
                }
            }
            if ($difference !== $expectedCents
                || (int)round($after['basket']['itemSum'] * 100) !== (int)round($before['itemSum'] * 100)) {
                throw new \RuntimeException('Der Versandbeitrag konnte nicht eindeutig berechnet werden. Bitte lade die Kasse neu.');
            }
            return $after;
        } catch (\Throwable $error) {
            $this->session->setSessionValue(self::CONSENT_KEY, $previous);
            $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
            throw $error;
        }
    }
}
