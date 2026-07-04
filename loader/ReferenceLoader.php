<?php

declare(strict_types=1);

namespace PtadLoader;

use PDO;
use Ptad\Database\Connection;
use PtadLoader\Reference\Countries;
use PtadLoader\Reference\SectionTypes;

/**
 * ============================================================
 * PTAD — Reference Data Loader
 * ============================================================
 * Loads the countries and section_types tables — the two small
 * reference tables every other table depends on via foreign key.
 * This MUST run before any module's tariff data is loaded.
 *
 * Re-runnable safely: uses INSERT ... ON DUPLICATE KEY UPDATE,
 * so running this again (e.g. after adding a new country) never
 * creates duplicates and never errors on existing rows.
 * ============================================================
 */
final class ReferenceLoader
{
    private PDO $pdo;

    /** @var string[] */
    private array $log = [];

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    public function run(): void
    {
        $this->loadCountries();
        $this->loadSectionTypes();
    }

    private function loadCountries(): void
    {
        $sql = "INSERT INTO countries (iso2, iso3, name, is_ldc, notes)
                VALUES (:iso2, :iso3, :name, :is_ldc, :notes)
                ON DUPLICATE KEY UPDATE
                    iso2 = VALUES(iso2),
                    iso3 = VALUES(iso3),
                    is_ldc = VALUES(is_ldc),
                    notes = VALUES(notes)";
        $stmt = $this->pdo->prepare($sql);

        $count = 0;
        foreach (Countries::DATA as $name => [$iso2, $iso3, $isLdc, $notes]) {
            $stmt->execute([
                ':iso2'   => $iso2,
                ':iso3'   => $iso3,
                ':name'   => $name,
                ':is_ldc' => $isLdc ? 1 : 0,
                ':notes'  => $notes,
            ]);
            $count++;
        }

        $this->log[] = "countries: {$count} rows loaded (of " . count(Countries::DATA) . " defined).";
    }

    private function loadSectionTypes(): void
    {
        $sql = "INSERT INTO section_types (code, display_name, description, typical_order)
                VALUES (:code, :display_name, :description, :typical_order)
                ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name),
                    description = VALUES(description),
                    typical_order = VALUES(typical_order)";
        $stmt = $this->pdo->prepare($sql);

        $count = 0;
        foreach (SectionTypes::DATA as $code => [$displayName, $description, $order]) {
            $stmt->execute([
                ':code'          => $code,
                ':display_name'  => $displayName,
                ':description'   => $description,
                ':typical_order' => $order,
            ]);
            $count++;
        }

        $this->log[] = "section_types: {$count} rows loaded (of " . count(SectionTypes::DATA) . " defined).";
    }

    /** @return string[] */
    public function getLog(): array
    {
        return $this->log;
    }
}
