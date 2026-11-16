<?php
declare(strict_types=1);

namespace JtlMedusa;

use DI\Container;
use GuzzleHttp\Client;
use Jtl\Connector\Core\Authentication\{TokenValidator, TokenValidatorInterface};
use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Connector\ConnectorInterface;
use Jtl\Connector\Core\Mapper\PrimaryKeyMapperInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class Connector implements ConnectorInterface
{
    private PrimaryKeyMapper $mapper;

    public function __construct(private Settings $settings, private string $stateDir) {}

    public function initialize(CoreConfigInterface $config, Container $container, EventDispatcher $dispatcher): void
    {
        $this->mapper = new PrimaryKeyMapper(new \PDO('sqlite:' . $this->stateDir . '/mappings.sqlite'));
        $http = new Client([
            'base_uri' => rtrim($this->settings->medusaUrl, '/') . '/',
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->medusaKey, 'Accept' => 'application/json'],
            'connect_timeout' => 5,
            'timeout' => 30,
        ]);
        $container->set(Settings::class, $this->settings);
        $container->set(PrimaryKeyMapper::class, $this->mapper);
        $container->set(CatalogWriter::class, new CatalogWriter(new MedusaClient($http), $this->mapper, $this->stateDir . '/catalog.lock'));
    }

    public function getPrimaryKeyMapper(): PrimaryKeyMapperInterface { return $this->mapper; }
    public function getTokenValidator(): TokenValidatorInterface { return new TokenValidator($this->settings->token); }
    public function getControllerNamespace(): string { return 'JtlMedusa\\Controller'; }
    public function getEndpointVersion(): string { return '0.1.0'; }
    public function getPlatformVersion(): string { return $this->settings->medusaVersion; }
    public function getPlatformName(): string { return 'Bulk'; }
}
