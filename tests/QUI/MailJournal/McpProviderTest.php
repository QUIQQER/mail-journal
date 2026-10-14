<?php

namespace QUITests\MailJournal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\AI\MCP\Server;
use QUI\Interfaces\Users\User;
use QUI\MailJournal\McpProvider;
use QUI\Permissions\Manager;
use ReflectionProperty;

class McpProviderTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private mixed $originalRequestUser;
    private ?Manager $originalRights;
    private string $directory;
    private string $outboxTable;
    private string $attachmentsTable;

    protected function setUp(): void
    {
        $this->originalConnection = QUI::getDataBaseConnection();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->connection);
        $this->originalRequestUser = (new ReflectionProperty(Server::class, 'RequestUser'))->getValue();
        $this->originalRights = QUI::$Rights;
        $this->setPermissions([
            'quiqqer.mail-journal.mcp' => true,
            'quiqqer.mail-journal.view' => true
        ]);

        $this->directory = sys_get_temp_dir() . '/mail-journal-mcp-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . '/attachments');
        file_put_contents($this->directory . '/attachments/invoice.pdf', "PDF\0binary\xffdata");
        file_put_contents($this->directory . '/secret.txt', 'private');

        $this->outboxTable = QUI::getDBTableName('mail_journal_outbox');
        $this->attachmentsTable = QUI::getDBTableName('mail_journal_outbox_attachments');
        $Outbox = new Table($this->outboxTable);

        $outboxFields = [
            'id',
            'create_date',
            'send_date',
            'subject',
            'body_html',
            'body_text',
            'mail_from',
            'mail_from_name',
            'mail_to',
            'reply_to',
            'mail_cc',
            'mail_bcc',
            'source_event',
            'meta'
        ];

        foreach ($outboxFields as $field) {
            $Outbox->addColumn($field, 'string', ['notnull' => false]);
        }

        $Outbox->addColumn('is_html', 'integer', ['default' => 1]);
        $Outbox->addColumn('archived', 'integer', ['default' => 0]);
        $Outbox->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($Outbox);
        $Attachments = new Table($this->attachmentsTable);

        foreach (['id', 'mail_id', 'create_date', 'filename', 'mime_type', 'path'] as $field) {
            $Attachments->addColumn($field, 'string', ['notnull' => false]);
        }

        $Attachments->addColumn('filesize', 'integer', ['notnull' => false]);
        $Attachments->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($Attachments);

        $this->insertMail('mail-1', '2026-10-01 10:00:00', 0);
        $this->insertMail('mail-2', '2026-10-02 10:00:00', 1);
        $this->insertMail('mail-3', '2026-10-02 10:00:00', 0);
        $this->insertAttachment('file-1', 'mail-1', $this->directory . '/attachments/invoice.pdf');
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Server::class, 'RequestUser'))->setValue(null, $this->originalRequestUser);
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->originalConnection);
        QUI::$Rights = $this->originalRights;
        $this->connection->close();

        foreach (glob($this->directory . '/attachments/*') ?: [] as $file) {
            unlink($file);
        }

        unlink($this->directory . '/secret.txt');
        rmdir($this->directory . '/attachments');
        rmdir($this->directory);
    }

    public function testRegistrationAndPermissionRevocationForEveryTool(): void
    {
        $tools = $this->getTools();
        self::assertSame([
            'mail_journal_search',
            'mail_journal_get',
            'mail_journal_attachment_get'
        ], array_keys($tools));

        $arguments = [
            'mail_journal_search' => [],
            'mail_journal_get' => ['mail-1'],
            'mail_journal_attachment_get' => ['file-1']
        ];

        $permissionSets = [
            [],
            ['quiqqer.mail-journal.mcp' => true],
            ['quiqqer.mail-journal.view' => true]
        ];

        foreach ($permissionSets as $permissions) {
            $this->setPermissions($permissions);
            self::assertSame([], $this->getTools());

            foreach ($tools as $name => $tool) {
                self::assertFalse($tool['inputSchema']['additionalProperties']);
                self::assertNotEmpty($tool['description']);
                $result = $tool['handler'](...$arguments[$name]);
                self::assertInstanceOf(CallToolResult::class, $result);
                self::assertTrue($result->isError);
            }
        }
    }

    public function testSearchPaginationAndCombinedFilters(): void
    {
        $search = $this->getTools()['mail_journal_search']['handler'];
        $result = $search(limit: 1);
        self::assertSame(3, $result['total']);
        self::assertSame('mail-3', $result['data'][0]['id']);
        self::assertSame('mail-2', $search(limit: 1, offset: 1)['data'][0]['id']);
        self::assertSame([], $search(offset: 10)['data']);

        $result = $search(
            query: 'customer@example.com invoice.pdf',
            dateFrom: '2026-10-01 10:00:00',
            dateTo: '2026-10-01 10:00:00',
            archived: false,
            hasAttachments: true
        );
        self::assertSame(1, $result['total']);
        self::assertSame('mail-1', $result['data'][0]['id']);
        self::assertSame(1, $result['data'][0]['attachment_count']);
        self::assertArrayNotHasKey('meta', $result['data'][0]);
        self::assertSame('mail-2', $search(archived: true, hasAttachments: false)['data'][0]['id']);
        self::assertSame(0, $search(query: "' OR 1=1 --")['total']);
        self::assertSame(3, $search(query: 'audit@example.com')['total']);
    }

    public function testGetMailPreservesBodiesAndOmitsPrivateMetadataAndPaths(): void
    {
        $result = $this->getTools()['mail_journal_get']['handler'](' mail-1 ');
        self::assertSame('mail-1', $result['id']);
        self::assertSame('<p>Invoice</p>', $result['body_html']);
        self::assertSame('Invoice text', $result['body_text']);
        self::assertSame('audit@example.com', $result['mail_bcc_display']);
        self::assertSame('queue', $result['delivery_type']);
        self::assertSame('file-1', $result['attachments'][0]['id']);
        self::assertArrayNotHasKey('path', $result['attachments'][0]);
        self::assertArrayNotHasKey('meta', $result);
        self::assertArrayNotHasKey('meta_json', $result);
        self::assertSame('direct', $this->getTools()['mail_journal_get']['handler']('mail-2')['delivery_type']);
    }

    public function testAttachmentChunksReconstructBinaryAndUseActualSize(): void
    {
        $get = $this->getTools()['mail_journal_attachment_get']['handler'];
        $content = '';
        $offset = 0;

        do {
            $result = $get('file-1', $offset, 4);
            self::assertSame('base64', $result['encoding']);
            self::assertSame($offset, $result['offset']);
            self::assertArrayNotHasKey('path', $result);
            $chunk = base64_decode($result['contentBase64'], true);
            self::assertIsString($chunk);
            $content .= $chunk;
            $offset = $result['nextOffset'];
        } while (!$result['eof']);

        self::assertSame("PDF\0binary\xffdata", $content);
        self::assertSame(strlen($content), $result['filesize']);
        self::assertSame('mail-1', $result['mail_id']);
        self::assertSame('application/pdf', $result['mime_type']);
        self::assertSame('invoice.pdf', $result['filename']);
        self::assertSame('', $get('file-1', $offset)['contentBase64']);
        self::assertTrue($get('file-1', $offset)['eof']);

        file_put_contents($this->directory . '/attachments/empty.txt', '');
        $this->insertAttachment('empty', 'mail-1', $this->directory . '/attachments/empty.txt');
        self::assertSame('', $get('empty')['contentBase64']);
        self::assertTrue($get('empty')['eof']);
    }

    public function testAttachmentRejectsUnavailableFilesOrPathsOutsideStorage(): void
    {
        $get = $this->getTools()['mail_journal_attachment_get']['handler'];
        $paths = [
            null,
            $this->directory . '/attachments/missing.pdf',
            $this->directory . '/secret.txt',
            $this->directory . '/attachments/../secret.txt',
            $this->directory . '/attachments'
        ];

        foreach ($paths as $path) {
            $this->connection->update($this->attachmentsTable, ['path' => $path], ['id' => 'file-1']);
            self::assertTrue($get('file-1')->isError);
        }

        symlink($this->directory . '/secret.txt', $this->directory . '/attachments/link');
        $this->connection->update(
            $this->attachmentsTable,
            ['path' => $this->directory . '/attachments/link'],
            ['id' => 'file-1']
        );
        self::assertTrue($get('file-1')->isError);
        $this->connection->update(
            $this->attachmentsTable,
            ['path' => $this->directory . '/attachments/invoice.pdf', 'mail_id' => 'missing'],
            ['id' => 'file-1']
        );
        self::assertTrue($get('file-1')->isError);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty mail id' => ['mail_journal_get', ['mailId' => ' ']];
        yield 'unknown mail' => ['mail_journal_get', ['mailId' => 'missing']];
        yield 'SQL in mail id' => ['mail_journal_get', ['mailId' => "' OR 1=1 --"]];
        yield 'empty attachment id' => ['mail_journal_attachment_get', ['attachmentId' => ' ']];
        yield 'unknown attachment' => ['mail_journal_attachment_get', ['attachmentId' => 'missing']];
        yield 'SQL in attachment id' => ['mail_journal_attachment_get', ['attachmentId' => "' OR 1=1 --"]];
        yield 'negative offset' => ['mail_journal_search', ['offset' => -1]];
        yield 'empty limit' => ['mail_journal_search', ['limit' => 0]];
        yield 'excessive limit' => ['mail_journal_search', ['limit' => 101]];
        yield 'invalid date' => ['mail_journal_search', ['dateFrom' => '2026-02-30 10:00:00']];
        yield 'invalid format' => ['mail_journal_search', ['dateTo' => '2026-10-01']];
        yield 'reversed dates' => ['mail_journal_search', [
            'dateFrom' => '2026-10-02 00:00:00',
            'dateTo' => '2026-10-01 00:00:00'
        ]];
        yield 'negative byte offset' => ['mail_journal_attachment_get', ['attachmentId' => 'file-1', 'offset' => -1]];
        yield 'offset beyond end' => ['mail_journal_attachment_get', ['attachmentId' => 'file-1', 'offset' => 100]];
        yield 'empty chunk' => ['mail_journal_attachment_get', ['attachmentId' => 'file-1', 'length' => 0]];
        yield 'excessive chunk' => ['mail_journal_attachment_get', ['attachmentId' => 'file-1', 'length' => 1048577]];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputsReturnMcpErrors(string $name, array $arguments): void
    {
        $result = $this->getTools()[$name]['handler'](...$arguments);
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    private function insertMail(string $id, string $sendDate, int $archived): void
    {
        $this->connection->insert($this->outboxTable, [
            'id' => $id,
            'create_date' => $sendDate,
            'send_date' => $sendDate,
            'subject' => 'Invoice',
            'body_html' => '<p>Invoice</p>',
            'body_text' => 'Invoice text',
            'mail_from' => 'sender@example.com',
            'mail_from_name' => 'Sender',
            'mail_to' => '[["customer@example.com", "Customer"]]',
            'mail_bcc' => '[["audit@example.com", ""]]',
            'source_event' => 'invoice',
            'meta' => $id === 'mail-1' ? '{"mailer":{"type":"queue","password":"secret"}}' : '{}',
            'archived' => $archived
        ]);
    }

    private function insertAttachment(string $id, string $mailId, string $path): void
    {
        $this->connection->insert($this->attachmentsTable, [
            'id' => $id,
            'mail_id' => $mailId,
            'create_date' => '2026-10-01 10:00:00',
            'filename' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'filesize' => 999,
            'path' => $path
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function getTools(): array
    {
        $Provider = new class ($this->directory . '/attachments') extends McpProvider {
            public function __construct(private readonly string $directory)
            {
            }

            protected function getAttachmentDirectory(): string
            {
                return $this->directory;
            }
        };
        $Builder = new Builder();
        $Provider->register($Builder);
        $tools = (new ReflectionProperty(Builder::class, 'tools'))->getValue($Builder);

        return array_column($tools, null, 'name');
    }

    /** @param array<string, bool> $permissions */
    private function setPermissions(array $permissions): void
    {
        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn('mail-journal-mcp-test-user');
        $User->method('getGroups')->willReturn([]);
        $Rights = $this->createMock(Manager::class);
        $Rights->method('getPermissions')->willReturn($permissions);
        QUI::$Rights = $Rights;
        (new ReflectionProperty(Server::class, 'RequestUser'))->setValue(null, $User);
    }
}
