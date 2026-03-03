<?php

namespace QUITests\MailJournal;

use PHPUnit\Framework\TestCase;
use QUI\MailJournal\Cron;

class CronTest extends TestCase
{
    public function testMonthRangeBuildsInclusiveExclusiveBounds(): void
    {
        $method = new \ReflectionMethod(Cron::class, 'getMonthRange');
        $method->setAccessible(true);

        $range = $method->invoke(null, '2023-11');

        $this->assertSame('2023-11-01 00:00:00', $range['from']);
        $this->assertSame('2023-12-01 00:00:00', $range['to']);
    }

    public function testToSqlValueFormatsPrimitiveTypes(): void
    {
        $method = new \ReflectionMethod(Cron::class, 'toSqlValue');
        $method->setAccessible(true);

        $this->assertSame('NULL', $method->invoke(null, null));
        $this->assertSame('1', $method->invoke(null, true));
        $this->assertSame('0', $method->invoke(null, false));
        $this->assertSame('42', $method->invoke(null, 42));
        $this->assertSame("'foo''bar'", $method->invoke(null, "foo'bar"));
    }

    public function testBuildInsertSqlCreatesValidStatement(): void
    {
        $method = new \ReflectionMethod(Cron::class, 'buildInsertSql');
        $method->setAccessible(true);

        $sql = $method->invoke(
            null,
            'tbl',
            ['id', 'subject', 'archived'],
            [
                [
                    'id' => 'mail-1',
                    'subject' => "Hello 'World'",
                    'archived' => 0
                ],
                [
                    'id' => 'mail-2',
                    'subject' => null,
                    'archived' => 1
                ]
            ]
        );

        $this->assertStringContainsString('INSERT INTO `tbl`', $sql);
        $this->assertStringContainsString("'mail-1'", $sql);
        $this->assertStringContainsString("'Hello ''World'''", $sql);
        $this->assertStringContainsString('NULL', $sql);
    }
}
