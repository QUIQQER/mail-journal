<?php

namespace QUITests\MailJournal;

use PHPUnit\Framework\TestCase;
use QUI\MailJournal\MailRepository;

class MailRepositoryTest extends TestCase
{
    public function testGetByIdReturnsNullForEmptyId(): void
    {
        $Repository = new MailRepository();

        $result = $Repository->getById('');

        $this->assertNull($result);
    }

    public function testDeleteByIdDelegatesToDeleteByIds(): void
    {
        $Repository = new class () extends MailRepository {
            /** @var array<int, mixed> */
            public array $lastIds = [];

            public function deleteByIds(array $mailIds): int
            {
                $this->lastIds = $mailIds;
                return 1;
            }
        };

        $result = $Repository->deleteById('mail-42');

        $this->assertTrue($result);
        $this->assertSame(['mail-42'], $Repository->lastIds);
    }

    public function testDeleteByIdsReturnsZeroForOnlyInvalidIds(): void
    {
        $Repository = new class () extends MailRepository {
            protected function assertDeletePermission(): void
            {
            }
        };

        $result = $Repository->deleteByIds(['', '   ', null, 123]);

        $this->assertSame(0, $result);
    }

    public function testNormalizeIdsTrimsFiltersAndDeduplicates(): void
    {
        $Repository = new MailRepository();
        $method = new \ReflectionMethod($Repository, 'normalizeIds');
        $method->setAccessible(true);

        $result = $method->invoke($Repository, [
            ' mail-1 ',
            'mail-2',
            '',
            'mail-1',
            '   ',
            42,
            null
        ]);

        $this->assertSame(['mail-1', 'mail-2'], $result);
    }
}
