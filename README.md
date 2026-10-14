QUIQQER Mail-Journal (E-mail Outbox)
========

![](bin/images/Readme.png)

The QUIQQER module `mail-journal` stores all system-sent e-mails in a searchable outbox.
It is designed for auditing and traceability of outgoing mails (for example invoices, orders, and customer notifications).

Package name:

    quiqqer/mail-journal


Features
--------

- Stores outgoing e-mails in a journal table
- Stores sender/recipient data (`to`, `cc`, `bcc`, `reply-to`)
- Stores subject, HTML body and text body
- Stores e-mail attachments and keeps metadata
- Provides archive flags/fields for later archive workflows
- Hooks into QUIQQER mail events


Installation
------------

Package name:

    quiqqer/mail-journal

MCP access
----------

With the optional `quiqqer/ai-mcp` module installed, run package setup to register
the mail journal provider. Grant the AI account both `quiqqer.mail-journal.mcp`
and `quiqqer.mail-journal.view`.

- `mail_journal_search`: search sending records by text, send date, archive state
  and attachment presence, with pagination (up to 100 results).
- `mail_journal_get`: retrieve a mail with its bodies, recipients and attachment metadata.
- `mail_journal_attachment_get`: retrieve base64 encoded attachment chunks. Decode
  each chunk separately and request `nextOffset` until `eof` is true.

Dates use system time in `YYYY-MM-DD HH:MM:SS` format. These read-only tools query
journal rows, including rows marked as archived; they do not query SQLite archive
files. A journal entry records sending and does not establish recipient delivery.
Internal storage paths and raw mailer metadata are omitted.


Contribute
----------

- Issue Tracker: https://dev.quiqqer.com/quiqqer/mail-journal/issues
- Source Code: https://dev.quiqqer.com/quiqqer/mail-journal


Support
-------

If you found any bugs or have suggestions for improvements, please send an e-mail to
[support@pcsg.de](mailto:support@pcsg.de).


License
-------

GPL-3.0+
