define('package/quiqqer/mail-journal/bin/javascript/backend/controls/Mail', [
    'qui/controls/desktop/Panel',
    'Ajax',
    'DownloadManager',
    'Locale',
    'css!package/quiqqer/mail-journal/bin/javascript/backend/controls/Mail.css'
], function (QUIPanel, QUIAjax, DownloadManager, QUILocale) {
    'use strict';

    const lg = 'quiqqer/mail-journal';

    const createRow = function (label, value) {
        const Row = new Element('div', {
            'class': 'mail-journal-mailPanel-row'
        });

        new Element('div', {
            text: label,
            'class': 'mail-journal-mailPanel-rowLabel'
        }).inject(Row);

        new Element('div', {
            text: value || '-',
            'class': 'mail-journal-mailPanel-rowValue'
        }).inject(Row);

        return Row;
    };

    const hasValue = function (value) {
        return !!(value && String(value).trim() && String(value).trim() !== '-');
    };

    return new Class({
        Extends: QUIPanel,
        Type: 'package/quiqqer/mail-journal/bin/javascript/backend/controls/Mail',

        Binds: [
            '$onCreate'
        ],

        options: {
            mailId: '',
            title: QUILocale.get(lg, 'mail.panel.title'),
            icon: 'fa fa-envelope-open'
        },

        initialize: function (options) {
            this.setAttributes({
                title: QUILocale.get(lg, 'mail.panel.title'),
                icon: 'fa fa-envelope-open'
            });

            this.parent(options);

            this.addEvents({
                onCreate: this.$onCreate
            });
        },

        $onCreate: function () {
            const mailId = this.getAttribute('mailId');
            const Body = this.getBody();

            Body.set('class', 'mail-journal-mailPanel-body');
            Body.scrollTop = 0;

            if (!mailId) {
                Body.set('text', QUILocale.get(lg, 'mail.panel.error.no_mail_id'));
                return;
            }

            this.Loader.show();

            QUIAjax.get('package_quiqqer_mail-journal_ajax_backend_get', (mail) => {
                if (!mail || !mail.id) {
                    this.Loader.hide();
                    Body.set('text', QUILocale.get(lg, 'mail.panel.error.not_found'));
                    return;
                }

                this.setAttribute('title', mail.subject || mail.id);

                Body.set('html', '');

                const Layout = new Element('div', {
                    'class': 'mail-journal-mailPanel-layout'
                }).inject(Body);

                const HeaderCard = new Element('div', {
                    'class': 'mail-journal-mailPanel-card'
                }).inject(Layout);

                new Element('h2', {
                    text: mail.subject || QUILocale.get(lg, 'mail.panel.title'),
                    'class': 'mail-journal-mailPanel-title'
                }).inject(HeaderCard);

                const HeaderGrid = new Element('div', {
                    'class': 'mail-journal-mailPanel-grid'
                }).inject(HeaderCard);

                createRow(QUILocale.get(lg, 'mail.field.send_date'), mail.send_date).inject(HeaderGrid);
                createRow(QUILocale.get(lg, 'mail.field.from'), mail.mail_from_display).inject(HeaderGrid);
                createRow(QUILocale.get(lg, 'mail.field.to'), mail.mail_to_display).inject(HeaderGrid);

                if (hasValue(mail.reply_to_display)) {
                    createRow(QUILocale.get(lg, 'mail.field.reply_to'), mail.reply_to_display).inject(HeaderGrid);
                }

                if (hasValue(mail.mail_cc_display)) {
                    createRow(QUILocale.get(lg, 'mail.field.cc'), mail.mail_cc_display).inject(HeaderGrid);
                }

                if (hasValue(mail.mail_bcc_display)) {
                    createRow(QUILocale.get(lg, 'mail.field.bcc'), mail.mail_bcc_display).inject(HeaderGrid);
                }

                if (mail.attachments && mail.attachments.length) {
                    const AttachmentWrap = new Element('div', {
                        'class': 'mail-journal-mailPanel-card'
                    }).inject(Layout);

                    new Element('h3', {
                        text: QUILocale.get(lg, 'mail.field.attachments'),
                        'class': 'mail-journal-mailPanel-sectionTitle'
                    }).inject(AttachmentWrap);

                    const List = new Element('ul', {
                        'class': 'mail-journal-mailPanel-list'
                    }).inject(AttachmentWrap);

                    mail.attachments.forEach((attachment) => {
                        const Item = new Element('li', {
                            'class': 'mail-journal-mailPanel-attachment'
                        }).inject(List);

                        new Element('span', {
                            text: attachment.filename + (attachment.mime_type ? ' (' + attachment.mime_type + ')' : ''),
                            'class': 'mail-journal-mailPanel-attachmentText'
                        }).inject(Item);

                        const Actions = new Element('span', {
                            'class': 'mail-journal-mailPanel-attachmentActions'
                        }).inject(Item);

                        new Element('button', {
                            html: '<span class="fa fa-eye"></span>',
                            title: QUILocale.get(lg, 'mail.attachment.view'),
                            'class': 'mail-journal-mailPanel-attachmentButton btn btn-secondary',
                            events: {
                                click: () => {
                                    if (!attachment.id) {
                                        return;
                                    }

                                    window.open(
                                        QUIAjax.$url + '?' + QUIAjax.parseParams(
                                            'package_quiqqer_mail-journal_ajax_backend_downloadAttachment',
                                            {
                                                'package': 'quiqqer/mail-journal',
                                                attachmentId: attachment.id,
                                                disposition: 'inline'
                                            }
                                        ),
                                        '_blank'
                                    );
                                }
                            }
                        }).inject(Actions);

                        new Element('button', {
                            html: '<span class="fa fa-download"></span>',
                            title: QUILocale.get(lg, 'mail.attachment.download'),
                            'class': 'mail-journal-mailPanel-attachmentButton btn btn-secondary',
                            events: {
                                click: () => {
                                    if (!attachment.id) {
                                        return;
                                    }

                                    DownloadManager.download(
                                        'package_quiqqer_mail-journal_ajax_backend_downloadAttachment',
                                        {
                                            'package': 'quiqqer/mail-journal',
                                            attachmentId: attachment.id,
                                            disposition: 'attachment'
                                        }
                                    );
                                }
                            }
                        }).inject(Actions);
                    });
                }

                if (mail.body_html) {
                    const HtmlWrap = new Element('div', {
                        'class': 'mail-journal-mailPanel-card'
                    }).inject(Layout);

                    new Element('h3', {
                        text: QUILocale.get(lg, 'mail.field.body_html'),
                        'class': 'mail-journal-mailPanel-sectionTitle'
                    }).inject(HtmlWrap);

                    const HtmlViewport = new Element('div', {
                        'class': 'mail-journal-mailPanel-htmlViewport'
                    }).inject(HtmlWrap);

                    new Element('div', {
                        html: mail.body_html,
                        'class': 'mail-journal-mailPanel-htmlCanvas'
                    }).inject(HtmlViewport);
                }

                if (mail.body_text) {
                    const TextWrap = new Element('div', {
                        'class': 'mail-journal-mailPanel-card'
                    }).inject(Layout);

                    new Element('h3', {
                        text: QUILocale.get(lg, 'mail.field.body_text'),
                        'class': 'mail-journal-mailPanel-sectionTitle'
                    }).inject(TextWrap);

                    const TextViewport = new Element('div', {
                        'class': 'mail-journal-mailPanel-textViewport'
                    }).inject(TextWrap);

                    new Element('pre', {
                        text: mail.body_text,
                        'class': 'mail-journal-mailPanel-text'
                    }).inject(TextViewport);
                }

                Body.scrollTop = 0;

                const showLayout = () => {
                    Layout.addClass('mail-journal-mailPanel-layout--ready');
                    Body.scrollTop = 0;
                    this.Loader.hide();
                };

                if (window.requestAnimationFrame) {
                    window.requestAnimationFrame(showLayout);
                    return;
                }

                showLayout();
            }, {
                'package': 'quiqqer/mail-journal',
                mailId: mailId
            });
        }
    });
});
