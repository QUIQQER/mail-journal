<?php

namespace QUITests\MailJournal;

use PHPUnit\Framework\TestCase;
use QUI\MailJournal\Mail;

class MailTest extends TestCase
{
    public function testFromDatabaseRowAndToArray(): void
    {
        $row = [
            'id' => 'mail-1',
            'create_date' => '2026-03-03 10:00:00',
            'send_date' => '2026-03-03 10:00:01',
            'subject' => 'Test Subject',
            'body_html' => '<p>Hello</p>',
            'body_text' => 'Hello',
            'mail_from' => 'from@example.com',
            'mail_from_name' => 'Sender',
            'mail_to' => '[["to@example.com","Receiver"]]',
            'reply_to' => '[["reply@example.com","Reply"]]',
            'mail_cc' => '[["cc@example.com","CC User"]]',
            'mail_bcc' => '[["bcc@example.com","BCC User"]]',
            'is_html' => '1',
            'source_event' => 'event.name',
            'meta' => '{"foo":"bar"}',
            'archived' => '0'
        ];

        $attachments = [
            [
                'id' => 'a1',
                'filename' => 'doc.pdf',
                'mime_type' => 'application/pdf',
                'filesize' => '123'
            ]
        ];

        $Mail = Mail::fromDatabaseRow($row, $attachments);
        $result = $Mail->toArray();

        $this->assertSame('mail-1', $Mail->getId());
        $this->assertSame('Sender <from@example.com>', $result['mail_from_display']);
        $this->assertSame('Receiver <to@example.com>', $result['mail_to_display']);
        $this->assertSame('Reply <reply@example.com>', $result['reply_to_display']);
        $this->assertSame('CC User <cc@example.com>', $result['mail_cc_display']);
        $this->assertSame('BCC User <bcc@example.com>', $result['mail_bcc_display']);
        $this->assertSame(1, $result['attachment_count']);
        $this->assertSame(123, $result['attachments'][0]['filesize']);
        $this->assertIsArray($result['meta']);
        $this->assertSame('bar', $result['meta']['foo']);
        $this->assertNotSame('', $result['meta_json']);
    }

    public function testFromDatabaseRowHandlesInvalidMetaAndAddressDisplayFallbacks(): void
    {
        $row = [
            'id' => 'mail-2',
            'create_date' => '2026-03-03 10:00:00',
            'send_date' => '',
            'mail_from' => 'from-only@example.com',
            'mail_from_name' => '',
            'mail_to' => 'not-json',
            'reply_to' => '',
            'mail_cc' => '',
            'mail_bcc' => '',
            'meta' => '{invalid-json'
        ];

        $Mail = Mail::fromDatabaseRow($row, []);
        $result = $Mail->toArray();

        $this->assertSame('mail-2', $Mail->getId());
        $this->assertNull($result['send_date']);
        $this->assertSame('from-only@example.com', $result['mail_from_display']);
        $this->assertSame('not-json', $result['mail_to_display']);
        $this->assertSame('', $result['reply_to_display']);
        $this->assertSame('', $result['mail_cc_display']);
        $this->assertSame('', $result['mail_bcc_display']);
        $this->assertSame([], $result['meta']);
        $this->assertSame('', $result['meta_json']);
        $this->assertSame(0, $result['attachment_count']);
    }

    public function testMailFromDisplayCanUseNameWithoutAddress(): void
    {
        $row = [
            'id' => 'mail-3',
            'create_date' => '2026-03-03 10:00:00',
            'mail_from' => '',
            'mail_from_name' => 'Only Name'
        ];

        $Mail = Mail::fromDatabaseRow($row, []);
        $result = $Mail->toArray();

        $this->assertSame('Only Name', $result['mail_from_display']);
    }
}
