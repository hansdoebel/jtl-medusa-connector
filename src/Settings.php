<?php
declare(strict_types=1);

namespace JtlMedusa;

final readonly class Settings
{
    public function __construct(
        public string $token,
        public string $medusaUrl,
        public string $medusaKey,
        public string $shippingProfile,
        public string $currency = 'eur',
        public string $language = 'de',
        public string $medusaVersion = '2',
    ) {
        if (strlen($token) < 32 || $medusaKey === '' || $shippingProfile === '') {
            throw new \InvalidArgumentException('Set JTL_TOKEN (32+ characters), MEDUSA_ADMIN_API_KEY and MEDUSA_SHIPPING_PROFILE_ID.');
        }
        $url = parse_url($medusaUrl);
        if (($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('MEDUSA_ADMIN_URL must be an HTTPS URL without credentials, query or fragment.');
        }
        if (!preg_match('/^[a-z]{3}$/D', $currency) || !preg_match('/^[a-z]{2}$/D', $language) || !preg_match('/^2(?:\.|$)/', $medusaVersion)) {
            throw new \InvalidArgumentException('Use a lowercase ISO currency, ISO 639-1 language and Medusa v2.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            getenv('JTL_TOKEN') ?: '',
            rtrim(getenv('MEDUSA_ADMIN_URL') ?: '', '/'),
            getenv('MEDUSA_ADMIN_API_KEY') ?: '',
            getenv('MEDUSA_SHIPPING_PROFILE_ID') ?: '',
            getenv('SHOP_CURRENCY') ?: 'eur',
            getenv('SHOP_LANGUAGE') ?: 'de',
            getenv('MEDUSA_VERSION') ?: '2',
        );
    }
}
