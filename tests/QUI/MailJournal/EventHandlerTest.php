<?php

namespace QUITests\MailJournal;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use QUI\Mail\Mailer;
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

    public function testStoreAttachmentsReturnsEarlyIfMailerHasNoAttachments(): void
    {
        $Mailer = $this->getMockBuilder(Mailer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toArray'])
            ->getMock();

        $Mailer->method('toArray')->willReturn([]);

        $method = new \ReflectionMethod(EventHandler::class, 'storeAttachments');
        $method->setAccessible(true);

        $method->invoke(null, 'mail-id', $Mailer);
        $this->assertTrue(true);
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
        $PHPMailer->From = 'from@example.com';
        $PHPMailer->FromName = 'From';
        $PHPMailer->addAddress('to@example.com', 'To');

        EventHandler::onMailerSend($Mailer, $PHPMailer);

        $this->assertTrue(true);
    }
}
