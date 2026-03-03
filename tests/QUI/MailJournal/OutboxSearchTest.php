<?php

namespace QUITests\MailJournal;

use PHPUnit\Framework\TestCase;
use QUI\MailJournal\OutboxSearch;

class OutboxSearchTest extends TestCase
{
    public function testAppendFreeTextSearchIgnoresEmptyInput(): void
    {
        $where = ['existing = 1'];
        $binds = ['a' => 'b'];

        $method = new \ReflectionMethod(OutboxSearch::class, 'appendFreeTextSearch');
        $method->setAccessible(true);
        $method->invokeArgs(null, [
            '   ',
            &$where,
            &$binds,
            'tbl_attachments'
        ]);

        $this->assertSame(['existing = 1'], $where);
        $this->assertSame(['a' => 'b'], $binds);
    }

    public function testAppendFreeTextSearchBuildsWhereAndBinds(): void
    {
        $where = [];
        $binds = [];

        $method = new \ReflectionMethod(OutboxSearch::class, 'appendFreeTextSearch');
        $method->setAccessible(true);
        $method->invokeArgs(null, [
            'foo bar',
            &$where,
            &$binds,
            'tbl_attachments'
        ]);

        $this->assertCount(2, $where);
        $this->assertArrayHasKey('search_0', $binds);
        $this->assertArrayHasKey('search_1', $binds);
        $this->assertSame('%foo%', $binds['search_0']);
        $this->assertSame('%bar%', $binds['search_1']);
        $this->assertStringContainsString('tbl_attachments', $where[0]);
    }

    public function testToBoolRecognizesTruthyValues(): void
    {
        $method = new \ReflectionMethod(OutboxSearch::class, 'toBool');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, '1'));
        $this->assertTrue($method->invoke(null, 'true'));
        $this->assertTrue($method->invoke(null, 'yes'));
        $this->assertTrue($method->invoke(null, ' ON '));
        $this->assertFalse($method->invoke(null, '0'));
        $this->assertFalse($method->invoke(null, 'false'));
        $this->assertFalse($method->invoke(null, ''));
    }

    public function testFormatAddressListFormatsJsonInput(): void
    {
        $method = new \ReflectionMethod(OutboxSearch::class, 'formatAddressList');
        $method->setAccessible(true);

        $json = '[["john@example.com","John"],["jane@example.com",""]]';
        $result = $method->invoke(null, $json);

        $this->assertSame('John <john@example.com>, jane@example.com', $result);
    }

    public function testFormatAddressListReturnsRawStringForInvalidJson(): void
    {
        $method = new \ReflectionMethod(OutboxSearch::class, 'formatAddressList');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'not-json');

        $this->assertSame('not-json', $result);
    }

    public function testFormatAddressListReturnsEmptyForEmptyInput(): void
    {
        $method = new \ReflectionMethod(OutboxSearch::class, 'formatAddressList');
        $method->setAccessible(true);

        $result = $method->invoke(null, '');

        $this->assertSame('', $result);
    }
}
