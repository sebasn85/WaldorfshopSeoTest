<?php
// Small contract fakes; real Plenty integration must additionally pass preview checks.
namespace Plenty\Plugin {
    class ConfigRepository {
        public $enabled = 'true';
        public function get($key, $default) { return $this->enabled; }
    }
}
namespace Plenty\Modules\Webshop\Contracts {
    class SessionStorageRepositoryContract {
        public $values = [];
        public $order;
        public function getSessionValue($key) { return $this->values[$key] ?? null; }
        public function setSessionValue($key, $value) { $this->values[$key] = $value; }
        public function getOrder() { return $this->order; }
    }
}
namespace Plenty\Modules\Frontend\Contracts {
    class Checkout {
        public $recalculate;
        public function setShippingProfileId($id, $force) { ($this->recalculate)(); }
    }
}
namespace Plenty\Modules\Basket\Contracts {
    class BasketRepositoryContract {
        public $basket;
        public function load() { return $this->basket; }
    }
}
namespace Plenty\Modules\Basket\Events\Basket {
    class AfterBasketChanged {
        private $basket;
        private $shipping;
        public function __construct($basket, $shipping) { $this->basket = $basket; $this->shipping = $shipping; }
        public function getBasket() { return $this->basket; }
        public function getShippingCosts() { return $this->shipping; }
        public function setShippingCosts($cost) { $this->shipping = $cost; }
    }
}
namespace IO\Services {
    class BasketService {
        public $repo;
        public function getBasketForTemplate() { return (array)$this->repo->load(); }
    }
}
namespace {
    use WaldorfshopSeoTest\Services\ClimateContributionService;
    use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
    function pluginApp($class) { return $GLOBALS['services'][$class]; }
    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
        $GLOBALS['checks']++;
    }
    require __DIR__.'/../src/Services/ClimateContributionService.php';
    $GLOBALS['checks'] = 0;
    $session = new \Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract();
    $repo = new \Plenty\Modules\Basket\Contracts\BasketRepositoryContract();
    $checkout = new \Plenty\Modules\Frontend\Contracts\Checkout();
    $config = new \Plenty\Plugin\ConfigRepository();
    $basketService = new \IO\Services\BasketService();
    $basketService->repo = $repo;
    $GLOBALS['services'][\IO\Services\BasketService::class] = $basketService;
    $repo->basket = (object)['id'=>10,'currency'=>'EUR','orderId'=>0,'basketItems'=>[1],
        'shippingProfileId'=>7,'itemSum'=>78.99,'shippingAmount'=>4.90,'basketAmount'=>83.89];
    $service = new ClimateContributionService($session, $repo, $checkout, $config);
    $breakCalculation = false;
    $checkout->recalculate = function () use ($repo, $service, &$breakCalculation) {
        $basket = $repo->basket;
        $event = new AfterBasketChanged($basket, $basket->itemSum >= 79 ? 0 : 4.90);
        $service->apply($event);
        $basket->shippingAmount = $event->getShippingCosts();
        $basket->basketAmount = round($basket->itemSum + $basket->shippingAmount, 2)
            + ($breakCalculation ? 1 : 0);
    };
    check(!$service->selected($repo->basket), 'Initial consent must be false');
    $result = $service->setSelected(true, 10);
    check($result['selected'], 'Explicit opt-in must be selected');
    check(round($result['basket']['basketAmount'], 2) === 84.39, 'Exact 50 cents added');
    check($result['basket']['itemSum'] === 78.99, 'Contribution must not cross 79 EUR merchandise threshold');
    $service->setSelected(true, 10);
    check(round($repo->basket->basketAmount, 2) === 84.39, 'Repeated request is idempotent');
    ($checkout->recalculate)();
    ($checkout->recalculate)();
    check(round($repo->basket->basketAmount, 2) === 84.39, 'Recalculation does not accumulate fees');
    $service->setSelected(false, 10);
    check(round($repo->basket->basketAmount, 2) === 83.89, 'Deselection restores total');
    $repo->basket->itemSum = 79.0;
    ($checkout->recalculate)();
    $result = $service->setSelected(true, 10);
    check($result['basket']['basketAmount'] === 79.5, 'Fee remains with free base shipping');
    $service->setSelected(false, 10);
    check($repo->basket->basketAmount === 79.0, 'Free-shipping deselection restores total');
    $breakCalculation = true;
    try { $service->setSelected(true, 10); throw new \Exception('Unexpected success'); }
    catch (\RuntimeException $error) { check(!$service->selected($repo->basket), 'Bad delta rolls back consent'); }
    $breakCalculation = false;
    ($checkout->recalculate)();
    try { $service->setSelected(true, 99); throw new \Exception('Unexpected stale basket success'); }
    catch (\RuntimeException $error) { check(!$service->selected($repo->basket), 'Stale basket cannot opt in'); }
    $service->setSelected(true, 10);
    $repo->basket->id = 11;
    check(!$service->selected($repo->basket), 'New basket requires new consent');
    $repo->basket->currency = 'CHF';
    check(!$service->eligible($repo->basket), 'CHF ineligible');
    $repo->basket->currency = 'EUR';
    $session->order = (object)['isNet'=>true];
    check(!$service->eligible($repo->basket), 'Net basket ineligible');
    $session->order = null;
    $repo->basket->basketItems = [];
    check(!$service->eligible($repo->basket), 'Empty basket ineligible');
    $service->apply(new AfterBasketChanged($repo->basket, 0));
    check($session->getSessionValue(ClimateContributionService::CONSENT_KEY) === null, 'Empty basket clears consent');
    $repo->basket->basketItems = [1];
    $config->enabled = 'false';
    check(!$service->eligible($repo->basket), 'Disabled plugin never accepts contribution');
    echo $GLOBALS['checks']." contribution checks passed\n";
}
