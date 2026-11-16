<?php
declare(strict_types=1);

namespace JtlMedusa;

use Jtl\Connector\Core\Mapper\PrimaryKeyMapperInterface;
use PDO;

final class PrimaryKeyMapper implements PrimaryKeyMapperInterface
{
    public function __construct(private PDO $db)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 10000');
        $db->exec('CREATE TABLE IF NOT EXISTS mappings (type INTEGER NOT NULL, endpoint TEXT NOT NULL, host INTEGER NOT NULL, PRIMARY KEY (type, endpoint), UNIQUE (type, host))');
    }

    public function getHostId(int $type, string $endpointId): ?int
    {
        $query = $this->db->prepare('SELECT host FROM mappings WHERE type = ? AND endpoint = ?');
        $query->execute([$type, $endpointId]);
        $value = $query->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function getEndpointId(int $type, int $hostId): ?string
    {
        $query = $this->db->prepare('SELECT endpoint FROM mappings WHERE type = ? AND host = ?');
        $query->execute([$type, $hostId]);
        $value = $query->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function save(int $type, string $endpointId, int $hostId): bool
    {
        if ($endpointId === '' || $hostId <= 0) {
            throw new \InvalidArgumentException('Mapping requires a nonempty endpoint ID and a positive Wawi ID.');
        }
        // Conflicting reassignments are errors, rather than silently pointing Wawi to another entity.
        $query = $this->db->prepare('INSERT INTO mappings (type, endpoint, host) VALUES (?, ?, ?) ON CONFLICT(type, endpoint) DO UPDATE SET host = excluded.host WHERE host = excluded.host');
        $query->execute([$type, $endpointId, $hostId]);
        if ($this->getHostId($type, $endpointId) !== $hostId || $this->getEndpointId($type, $hostId) !== $endpointId) {
            throw new \RuntimeException('Conflicting JTL identity mapping.');
        }
        return true;
    }

    public function delete(int $type, ?string $endpointId = null, ?int $hostId = null): bool
    {
        if ($endpointId === null && $hostId === null) {
            throw new \InvalidArgumentException('Use clear(type) to clear a mapping type.');
        }
        $sql = 'DELETE FROM mappings WHERE type = ?';
        $args = [$type];
        if ($endpointId !== null) { $sql .= ' AND endpoint = ?'; $args[] = $endpointId; }
        if ($hostId !== null) { $sql .= ' AND host = ?'; $args[] = $hostId; }
        return $this->db->prepare($sql)->execute($args);
    }

    public function clear(?int $type = null): bool
    {
        return $type === null
            ? $this->db->exec('DELETE FROM mappings') !== false
            : $this->db->prepare('DELETE FROM mappings WHERE type = ?')->execute([$type]);
    }
}
