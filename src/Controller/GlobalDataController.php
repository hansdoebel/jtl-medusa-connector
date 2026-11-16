<?php
declare(strict_types=1);

namespace JtlMedusa\Controller;

use Jtl\Connector\Core\Controller\PullInterface;
use Jtl\Connector\Core\Model\{Currency, CustomerGroup, CustomerGroupI18n, GlobalData, Identity, Language, QueryFilter};
use JtlMedusa\Settings;

final class GlobalDataController implements PullInterface
{
    public function __construct(private Settings $settings) {}

    public function pull(QueryFilter $queryFilter): array
    {
        $global = new GlobalData();
        $global->addLanguage((new Language())->setId(new Identity('language-' . $this->settings->language))->setLanguageISO($this->settings->language)->setIsDefault(true));
        $global->addCurrency((new Currency())->setId(new Identity('currency-' . $this->settings->currency))->setIso(strtoupper($this->settings->currency))->setName(strtoupper($this->settings->currency))->setFactor(1.0)->setIsDefault(true));
        $global->addCustomerGroup((new CustomerGroup())->setId(new Identity('retail'))->setIsDefault(true)->setApplyNetPrice(true)->addI18n((new CustomerGroupI18n())->setLanguageIso($this->settings->language)->setName('Retail')));
        return [$global];
    }
}
