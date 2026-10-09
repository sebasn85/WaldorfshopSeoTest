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
        $order = $this->session->getOrder();
        return $this->enabled() && $basket && $basket->id > 0
            && $basket->currency === 'EUR' && !$basket->orderId
            && count($basket->basketItems) > 0
            && (!$order || !$order->isNet);
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
            $event->addAdditionalFee(self::AMOUNT);
        }
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
        $this->session->setSessionValue(self::CONSENT_KEY, [
            'basketId' => $basketId,
            'accepted' => $selected,
            'amount' => self::AMOUNT,
            'acceptedAt' => $selected ? time() : null,
            'textVersion' => '2026-10-09'
        ]);

        try {
            $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
            $after = $this->state();
            $expectedCents = $selected ? 50 : -50;
            $difference = (int)round(($after['basket']['basketAmount'] - $before['basketAmount']) * 100);
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
