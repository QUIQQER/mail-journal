E-Mail Postausgang
========

The QUIQQER module `mail-journal` stores all system-sent e-mails in a searchable outbox.
It is designed for auditing and traceability of outgoing mails (for example invoices, orders, and customer notifications).

![](bin/images/Readme.png)

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


Technical Notes
---------------

- Event hook: `mailerSend`
- Event handler: `\QUI\MailJournal\EventHandler::onMailerSend`
- Main tables:
    - `mail_journal_outbox`
    - `mail_journal_outbox_attachments`
- IDs are UUID-based


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
