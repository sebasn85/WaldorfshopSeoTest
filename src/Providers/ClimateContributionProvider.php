<?php
namespace WaldorfshopSeoTest\Providers;

use Plenty\Plugin\Templates\Twig;
use WaldorfshopSeoTest\Services\ClimateContributionService;

class ClimateContributionProvider
{
    public function call(Twig $twig)
    {
        if (!pluginApp(ClimateContributionService::class)->enabled()) {
            return '';
        }
        return $twig->render('WaldorfshopSeoTest::Checkout.ClimateContribution');
    }
}
