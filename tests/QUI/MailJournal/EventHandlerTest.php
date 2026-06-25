<?php

namespace QUITests\MailJournal;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use QUI\Mail\Mailer;
use QUI\Mail\Queue;
use QUI\MailJournal\EventHandler;

class EventHandlerTest extends TestCase
{
    public function testExtractAttachmentsReturnsEmptyIfNoKnownKeyExists(): void
    {
        $method = new \ReflectionMethod(EventHandler::class, 'extractAttachments');
        $method->setAccessible(true);

        $result = $method->invoke(null, [
            'foo' => 'bar'
        ]);

        $this->assertSame([], $result);
    }

    public function testExtractAttachmentsUsesCorrectKey(): void
    {
        $method = new \ReflectionMethod(EventHandler::class, 'extractAttachments');
        $method->setAccessible(true);

        $result = $method->invoke(null, [
            'attachments' => ['a.pdf']
        ]);

        $this->assertSame(['a.pdf'], $result);
    }

    public function testExtractAttachmentsSupportsLegacyKey(): void
    {
        $method = new \ReflectionMethod(EventHandler::class, 'extractAttachments');
        $method->setAccessible(true);

        $result = $method->invoke(null, [
            'attachements' => ['legacy.pdf']
        ]);

        $this->assertSame(['legacy.pdf'], $result);
    }

    public function testEncodeAddressListEncodesToJson(): void
    {
        $method = new \ReflectionMethod(EventHandler::class, 'encodeAddressList');
        $method->setAccessible(true);

        $result = $method->invoke(null, [['john@example.com', 'John']]);

        $this->assertSame('[["john@example.com","John"]]', $result);
    }

    public function testEncodeMetaWrapsMailerArray(): void
    {
        $Mailer = $this->getMockBuilder(Mailer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toArray'])
            ->getMock();

        $Mailer->method('toArray')->willReturn([
            'subject' => 'Unit Test'
        ]);

        $method = new \ReflectionMethod(EventHandler::class, 'encodeMeta');
        $method->setAccessible(true);

        $json = $method->invoke(null, $Mailer);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('mailer', $decoded);
        $this->assertSame('Unit Test', $decoded['mailer']['subject']);
    }

    public function testShouldSkipJournalEntryForMailerWhenQueueIsEnabled(): void
    {
        $Mailer = $this->getMockBuilder(Mailer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $method = new \ReflectionMethod(EventHandler::class, 'shouldSkipJournalEntry');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, $Mailer, true));
        $this->assertFalse($method->invoke(null, $Mailer, false));
    }

    public function testShouldNotSkipJournalEntryForQueueWhenQueueIsEnabled(): void
    {
        $Queue = new Queue();

        $method = new \ReflectionMethod(EventHandler::class, 'shouldSkipJournalEntry');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, $Queue, true));
    }

    public function testStoreAttachmentsReturnsEarlyIfMailerHasNoAttachments(): void
    {
        $method = new \ReflectionMethod(EventHandler::class, 'storeAttachments');
        $method->setAccessible(true);

        $PHPMailer = new PHPMailer(true);
        $method->invoke(null, 'mail-id', $PHPMailer);
        $this->assertTrue(true);
    }

    public function testEncodeMetaWrapsQueueMetadata(): void
    {
        $Queue = new Queue();

        $method = new \ReflectionMethod(EventHandler::class, 'encodeMeta');
        $method->setAccessible(true);

        $json = $method->invoke(null, $Queue);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('mailer', $decoded);
        $this->assertSame('queue', $decoded['mailer']['type']);
        $this->assertSame(Queue::class, $decoded['mailer']['class']);
    }

    public function testResolveIsHtmlForQueueUsesPhpMailerContentType(): void
    {
        $method = new \ReflectionMethod(EventHandler::class, 'resolveIsHtml');
        $method->setAccessible(true);

        $Queue = new Queue();
        $PHPMailer = new PHPMailer(true);
        $PHPMailer->isHTML();

        $isHtml = $method->invoke(null, $Queue, $PHPMailer);

        $this->assertSame(1, $isHtml);
    }

    public function testOnMailerSendSwallowsErrors(): void
    {
        $Mailer = $this->getMockBuilder(Mailer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAttribute', 'toArray'])
            ->getMock();

        $Mailer->method('getAttribute')->willReturn(1);
        $Mailer->method('toArray')->willReturn([]);

        $PHPMailer = new PHPMailer(true);
        $PHPMailer->Subject = 'Subject';
        $PHPMailer->Body = '<p>Body</p>';
        $PHPMailer->AltBody = 'Body';
        $PHPMailer->From = 'from@mailjournal.invalid';
        $PHPMailer->FromName = 'From';
        $PHPMailer->addAddress('support@pcsg.de', 'To');

        EventHandler::onMailerSend($Mailer, $PHPMailer);

        $this->assertTrue(true);
    }

    public function testOnMailerSendSwallowsErrorsWithQueue(): void
    {
        $Queue = new Queue();

        $PHPMailer = new PHPMailer(true);
        $PHPMailer->Subject = 'Subject';
        $PHPMailer->Body = '<p>Body</p>';
        $PHPMailer->AltBody = 'Body';
        $PHPMailer->From = 'from@mailjournal.invalid';
        $PHPMailer->FromName = 'From';
        $PHPMailer->addAddress('support@pcsg.de', 'To');

        EventHandler::onMailerSend($Queue, $PHPMailer);

        $this->assertTrue(true);
    }
}
