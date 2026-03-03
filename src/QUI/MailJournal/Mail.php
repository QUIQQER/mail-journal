<?php

namespace QUI\MailJournal;

use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;

class Mail
{
    /**
     * @param array<int, array<string, mixed>> $attachments
     * @param array<string, mixed> $meta
     */
    public function __construct(
        protected string $id,
        protected string $createDate,
        protected ?string $sendDate,
        protected ?string $subject,
        protected ?string $bodyHtml,
        protected ?string $bodyText,
        protected ?string $mailFrom,
        protected ?string $mailFromName,
        protected ?string $mailTo,
        protected ?string $replyTo,
        protected ?string $mailCc,
        protected ?string $mailBcc,
        protected int $isHtml,
        protected ?string $sourceEvent,
        protected array $meta,
        protected int $archived,
        protected array $attachments
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $attachments
     */
    public static function fromDatabaseRow(array $row, array $attachments = []): self
    {
        $meta = [];

        if (!empty($row['meta'])) {
            $metaDecoded = json_decode((string)$row['meta'], true);

            if (is_array($metaDecoded)) {
                $meta = $metaDecoded;
            }
        }

        foreach ($attachments as $i => $attachment) {
            $attachments[$i]['filesize'] = (int)($attachment['filesize'] ?? 0);
        }

        return new self(
            (string)($row['id'] ?? ''),
            (string)($row['create_date'] ?? ''),
            empty($row['send_date']) ? null : (string)$row['send_date'],
            isset($row['subject']) ? (string)$row['subject'] : null,
            isset($row['body_html']) ? (string)$row['body_html'] : null,
            isset($row['body_text']) ? (string)$row['body_text'] : null,
            isset($row['mail_from']) ? (string)$row['mail_from'] : null,
            isset($row['mail_from_name']) ? (string)$row['mail_from_name'] : null,
            isset($row['mail_to']) ? (string)$row['mail_to'] : null,
            isset($row['reply_to']) ? (string)$row['reply_to'] : null,
            isset($row['mail_cc']) ? (string)$row['mail_cc'] : null,
            isset($row['mail_bcc']) ? (string)$row['mail_bcc'] : null,
            (int)($row['is_html'] ?? 0),
            isset($row['source_event']) ? (string)$row['source_event'] : null,
            $meta,
            (int)($row['archived'] ?? 0),
            $attachments
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'create_date' => $this->createDate,
            'send_date' => $this->sendDate,
            'subject' => $this->subject,
            'body_html' => $this->bodyHtml,
            'body_text' => $this->bodyText,
            'mail_from' => $this->mailFrom,
            'mail_from_name' => $this->mailFromName,
            'mail_to' => $this->mailTo,
            'reply_to' => $this->replyTo,
            'mail_cc' => $this->mailCc,
            'mail_bcc' => $this->mailBcc,
            'is_html' => $this->isHtml,
            'source_event' => $this->sourceEvent,
            'archived' => $this->archived,
            'mail_from_display' => self::formatFromAddress($this->mailFromName, $this->mailFrom),
            'mail_to_display' => self::formatAddressList($this->mailTo),
            'reply_to_display' => self::formatAddressList($this->replyTo),
            'mail_cc_display' => self::formatAddressList($this->mailCc),
            'mail_bcc_display' => self::formatAddressList($this->mailBcc),
            'attachments' => $this->attachments,
            'attachment_count' => count($this->attachments),
            'meta' => $this->meta,
            'meta_json' => ''
        ];

        if (!empty($this->meta)) {
            $jsonMeta = json_encode($this->meta, JSON_PRETTY_PRINT);

            if ($jsonMeta !== false) {
                $result['meta_json'] = $jsonMeta;
            }
        }

        return $result;
    }

    protected static function formatFromAddress(?string $name, ?string $address): string
    {
        $name = trim((string)$name);
        $address = trim((string)$address);

        if ($name !== '' && $address !== '') {
            return $name . ' <' . $address . '>';
        }

        if ($address !== '') {
            return $address;
        }

        return $name;
    }

    protected static function formatAddressList(?string $json): string
    {
        if (empty($json)) {
            return '';
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return $json;
        }

        $addresses = [];

        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $address = trim((string)($entry[0] ?? ''));
                $name = trim((string)($entry[1] ?? ''));

                if (!empty($name) && !empty($address)) {
                    $addresses[] = $name . ' <' . $address . '>';
                    continue;
                }

                if (!empty($address)) {
                    $addresses[] = $address;
                }

                continue;
            }

            if (is_string($entry)) {
                $entry = trim($entry);

                if (!empty($entry)) {
                    $addresses[] = $entry;
                }
            }
        }

        return implode(', ', $addresses);
    }
}
