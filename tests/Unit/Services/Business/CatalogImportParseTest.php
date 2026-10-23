<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\CatalogImportParser;
use PHPUnit\Framework\TestCase;

final class CatalogImportParseTest extends TestCase
{
    public function testParseCsvRequiresHeaderColumns(): void
    {
        $bad = "name,price\nPhone,1000\n";
        $this->assertNull(CatalogImportParser::parseCsv($bad));

        $good = "title,description,type,category,location,price,external_key\n"
            . "Phone,Good phone,used,Электроника,Алматы,15000,SKU-1\n";
        $rows = CatalogImportParser::parseCsv($good);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Phone', $rows[0]['title']);
        $this->assertSame('SKU-1', $rows[0]['external_key']);
    }

    public function testParseXmlCatalog(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<catalog>
  <product>
    <external_key>A1</external_key>
    <title>Платье</title>
    <description>Новое</description>
    <type>new</type>
    <category>Одежда</category>
    <location>Караганда</location>
    <price>9000</price>
  </product>
</catalog>
XML;
        $rows = CatalogImportParser::parseXml($xml);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Платье', $rows[0]['title']);
        $this->assertSame('A1', $rows[0]['external_key']);
    }

    public function testNormalizeRowRejectsBadTypeAndMissingPrice(): void
    {
        $badType = CatalogImportParser::normalizeRow([
            'title' => 'X',
            'description' => 'Y',
            'type' => 'auction',
            'category' => 'Разное',
            'location' => 'Алматы',
            'price' => 100,
        ]);
        $this->assertFalse($badType['ok']);

        $noPrice = CatalogImportParser::normalizeRow([
            'title' => 'X',
            'description' => 'Y',
            'type' => 'used',
            'category' => 'Разное',
            'location' => 'Алматы',
            'price' => 0,
        ]);
        $this->assertFalse($noPrice['ok']);

        $ok = CatalogImportParser::normalizeRow([
            'title' => 'X',
            'description' => 'Y',
            'type' => 'used',
            'category' => 'Разное',
            'location' => 'Алматы',
            'price' => '12 500 ₸',
            'external_key' => 'SKU',
        ]);
        $this->assertTrue($ok['ok']);
        $this->assertSame(12500, $ok['row']['price']);
    }

    public function testRejectsXmlWithEntityExpansionAttemptAsInvalidOrSafe(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><catalog><product><title>&xxe;</title><description>d</description><type>used</type><category>c</category><location>l</location></product></catalog>';
        $rows = CatalogImportParser::parseXml($xxe);
        if ($rows === null) {
            $this->assertNull($rows);

            return;
        }
        $title = (string) ($rows[0]['title'] ?? '');
        $this->assertStringNotContainsString('root:', $title);
    }
}
