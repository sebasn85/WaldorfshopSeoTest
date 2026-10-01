<?php
namespace WaldorfshopSeoTest\Providers;

use Plenty\Modules\Webshop\Template\Providers\TemplateServiceProvider;

/** Isolated template copy for testing; no routes, migrations or global settings. */
class SeoTestServiceProvider extends TemplateServiceProvider
{
    public function register()
    {
    }

    public function boot()
    {
        $this->overrideTemplate(
            'Ceres::PageDesign.Partials.Head',
            'WaldorfshopSeoTest::PageDesign.Partials.Head'
        );
        $this->overrideTemplate(
            'Waldorfshop7::PageDesign.Partials.Head',
            'WaldorfshopSeoTest::PageDesign.Partials.Head'
        );
    }
}
