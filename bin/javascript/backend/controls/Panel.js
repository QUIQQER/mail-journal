define('package/quiqqer/mail-journal/bin/javascript/backend/controls/Panel', [
    'qui/controls/desktop/Panel',
    'qui/controls/windows/Confirm',
    'controls/grid/Grid',
    'utils/Panels',
    'package/quiqqer/mail-journal/bin/javascript/backend/controls/Mail',
    'DownloadManager',
    'Ajax',
    'Locale'
], function (QUIPanel, QUIConfirm, Grid, PanelUtils, MailPanel, DownloadManager, QUIAjax, QUILocale) {
    'use strict';

    const lg = 'quiqqer/mail-journal';

    return new Class({

        Extends: QUIPanel,
        Type: 'package/quiqqer/mail-journal/bin/javascript/backend/controls/Panel',

        Binds: [
            '$onCreate',
            '$onInject',
            '$onResize',
            '$gridRefresh',
            '$refreshGridButtons',
            '$onGridDblClick',
            '$onFilterChange',
            '$onSearchKeyUp',
            '$onSearchInput',
            '$onDeleteClick',
            '$openArchiveWindow',
            '$openFilterDialog'
        ],

        initialize: function (options) {
            this.setAttributes({
                title: QUILocale.get(lg, 'panel.title'),
                icon: 'fa fa-envelope'
            });

            this.parent(options);

            this.$Container = null;
            this.$GridContainer = null;
            this.$Grid = null;
            this.$SearchInput = null;
            this.$SearchDelay = null;
            this.$DeleteButton = null;
            this.$FilterValues = {
                dateFrom: '',
                dateTo: '',
                hasAttachments: '',
                archived: ''
            };

            this.addEvents({
                onCreate: this.$onCreate,
                onInject: this.$onInject,
                onResize: this.$onResize
            });
        },

        $onCreate: function () {
            this.$Container = new Element('div', {
                styles: {
                    width: '100%',
                    height: '100%'
                }
            }).inject(this.getBody());

            this.$SearchInput = new Element('input', {
                type: 'search',
                placeholder: QUILocale.get(lg, 'filter.search.placeholder'),
                styles: {
                    'float': 'right',
                    margin: '10px 0 0 0',
                    width: 260
                },
                events: {
                    keyup: this.$onSearchKeyUp,
                    input: this.$onSearchInput,
                    search: this.$onSearchKeyUp
                }
            });

            this.addButton({
                name: 'archives',
                text: QUILocale.get(lg, 'archive.button'),
                textimage: 'fa fa-archive',
                events: {
                    onClick: this.$openArchiveWindow
                }
            });
            this.addButton({
                name: 'filter',
                text: QUILocale.get(lg, 'filter.button'),
                textimage: 'fa fa-sliders',
                styles: {
                    'float': 'right'
                },
                events: {
                    onClick: this.$openFilterDialog
                }
            });
            this.addButton({
                type: 'separator',
                styles: {
                    'float': 'right'
                }
            });
            this.addButton({
                name: 'search',
                icon: 'fa fa-search',
                styles: {
                    'float': 'right'
                },
                events: {
                    onClick: this.$onFilterChange
                }
            });
            this.addButton(this.$SearchInput);

            this.$GridContainer = new Element('div', {
                styles: {
                    width: '100%',
                    height: '100%'
                }
            }).inject(this.$Container);

            this.$Grid = new Grid(this.$GridContainer, {
                columnModel: [{
                    header: QUILocale.get(lg, 'grid.header.send_date'),
                    dataIndex: 'send_date',
                    dataType: 'date',
                    width: 160
                }, {
                    header: QUILocale.get(lg, 'grid.header.subject'),
                    dataIndex: 'subject',
                    dataType: 'string',
                    width: 300
                }, {
                    header: QUILocale.get(lg, 'grid.header.from'),
                    dataIndex: 'mail_from',
                    dataType: 'string',
                    width: 220
                }, {
                    header: QUILocale.get(lg, 'grid.header.to'),
                    dataIndex: 'mail_to_display',
                    dataType: 'string',
                    width: 300
                }, {
                    header: QUILocale.get(lg, 'grid.header.attachments'),
                    dataIndex: 'attachment_count',
                    dataType: 'number',
                    width: 100
                }, {
                    header: QUILocale.get(lg, 'grid.header.archived'),
                    dataIndex: 'archived',
                    dataType: 'bool',
                    width: 80
                }],
                multipleSelection: true,
                pagination: true,
                serverSort: true,
                sortOn: 'send_date',
                sortBy: 'DESC',
                perPage: 20,
                buttons: [{
                    name: 'delete',
                    text: '',
                    icon: 'fa fa-trash',
                    disabled: true,
                    position: 'right',
                    events: {
                        onClick: this.$onDeleteClick
                    }
                }]
            });

            this.$DeleteButton = this.$Grid.getButtons().filter((Button) => {
                return Button.getAttribute('name') === 'delete';
            })[0] || null;

            this.$Grid.addEvents({
                onRefresh: this.$gridRefresh,
                onClick: this.$refreshGridButtons,
                onDblClick: this.$onGridDblClick
            });

            this.$gridRefresh();
        },

        $onInject: function () {
            this.$gridRefresh();
        },

        $gridRefresh: function () {
            const grid = this.$Grid;

            if (!grid) {
                return;
            }

            this.Loader.show();

            QUIAjax.get('package_quiqqer_mail-journal_ajax_backend_list', (result) => {
                if (!this.$Grid) {
                    this.Loader.hide();
                    return;
                }

                this.$Grid.setData(result);
                this.$refreshGridButtons();
                this.Loader.hide();
            }, {
                'package': 'quiqqer/mail-journal',
                params: JSON.encode({
                    perPage: grid.getAttribute('perPage') || 20,
                    page: grid.getAttribute('page') || 1,
                    sortOn: grid.getAttribute('sortOn') || 'send_date',
                    sortBy: grid.getAttribute('sortBy') || 'DESC',
                    search: this.$SearchInput ? this.$SearchInput.value : '',
                    dateFrom: this.$FilterValues.dateFrom,
                    dateTo: this.$FilterValues.dateTo,
                    hasAttachments: this.$FilterValues.hasAttachments,
                    archived: this.$FilterValues.archived
                })
            });
        },

        $refreshGridButtons: function () {
            if (!this.$Grid || !this.$DeleteButton) {
                return;
            }

            const selected = this.$Grid.getSelectedData();

            if (!selected || !selected.length) {
                this.$DeleteButton.disable();
                return;
            }

            this.$DeleteButton.enable();
        },

        $onFilterChange: function () {
            if (!this.$Grid) {
                return;
            }

            this.$Grid.setAttribute('page', 1);
            this.$gridRefresh();
        },

        $onSearchKeyUp: function (event) {
            if (event && event.key === 'enter') {
                this.$onFilterChange();
                return;
            }

            if (this.$SearchDelay) {
                clearTimeout(this.$SearchDelay);
            }

            this.$SearchDelay = (() => {
                this.$onFilterChange();
            }).delay(350);
        },

        $onSearchInput: function () {
            if (!this.$SearchInput) {
                return;
            }

            if (this.$SearchInput.value !== '') {
                return;
            }

            if (this.$SearchDelay) {
                clearTimeout(this.$SearchDelay);
                this.$SearchDelay = null;
            }

            this.$onFilterChange();
        },

        $onDeleteClick: function () {
            if (!this.$Grid) {
                return;
            }

            const selected = this.$Grid.getSelectedData();

            if (!selected || !selected.length) {
                return;
            }

            const mailIds = [];
            const information = ['<ul style="margin:0;padding-left:18px;">'];

            selected.forEach((entry) => {
                const id = String(entry.id || '').trim();

                if (!id) {
                    return;
                }

                mailIds.push(id);

                let subject = String(entry.subject || '').trim();

                if (!subject) {
                    subject = '-';
                }

                subject = subject
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');

                information.push('<li><strong>' + id + '</strong>: ' + subject + '</li>');
            });

            information.push('</ul>');

            if (!mailIds.length) {
                return;
            }

            new QUIConfirm({
                icon: 'fa fa-trash',
                texticon: 'fa fa-trash',
                title: QUILocale.get(lg, 'delete.dialog.title'),
                text: QUILocale.get(lg, 'delete.dialog.text'),
                information: information.join(''),
                autoclose: false,
                maxWidth: 650,
                maxHeight: 500,
                ok_button: {
                    text: QUILocale.get(lg, 'delete.button'),
                    textimage: 'fa fa-trash'
                },
                events: {
                    onSubmit: (Win) => {
                        Win.Loader.show();

                        QUIAjax.get('package_quiqqer_mail-journal_ajax_backend_delete', () => {
                            Win.close();
                            this.$onFilterChange();
                            this.$refreshGridButtons();
                        }, {
                            'package': 'quiqqer/mail-journal',
                            mailId: JSON.encode(mailIds)
                        });
                    }
                }
            }).open();
        },

        $openArchiveWindow: function () {
            new QUIConfirm({
                title: QUILocale.get(lg, 'archive.window.title'),
                icon: 'fa fa-archive',
                autoclose: false,
                maxWidth: 760,
                maxHeight: 520,
                ok_button: false,
                cancel_button: {
                    text: QUILocale.get('quiqqer/core', 'close'),
                    textimage: 'fa fa-close'
                },
                events: {
                    onOpen: (Win) => {
                        const Content = Win.getContent();
                        Content.setStyles({
                            padding: 15
                        });
                        Content.set('html', '');
                        Win.Loader.show();

                        QUIAjax.get('package_quiqqer_mail-journal_ajax_backend_listArchives', (archives) => {
                            Win.Loader.hide();

                            if (!archives || !archives.length) {
                                new Element('div', {
                                    text: QUILocale.get(lg, 'archive.empty'),
                                    styles: {
                                        color: '#6b7280'
                                    }
                                }).inject(Content);
                                return;
                            }

                            const Table = new Element('table', {
                                styles: {
                                    width: '100%',
                                    borderCollapse: 'collapse'
                                }
                            }).inject(Content);

                            const Thead = new Element('thead').inject(Table);
                            const HeadRow = new Element('tr').inject(Thead);

                            [
                                QUILocale.get(lg, 'archive.col.file'),
                                QUILocale.get(lg, 'archive.col.size'),
                                QUILocale.get(lg, 'archive.col.date'),
                                ''
                            ].forEach((title) => {
                                new Element('th', {
                                    text: title,
                                    styles: {
                                        textAlign: 'left',
                                        borderBottom: '1px solid #d1d5db',
                                        padding: '8px'
                                    }
                                }).inject(HeadRow);
                            });

                            const Tbody = new Element('tbody').inject(Table);

                            archives.forEach((entry) => {
                                const Row = new Element('tr').inject(Tbody);

                                new Element('td', {
                                    text: entry.file || '-',
                                    styles: {
                                        borderBottom: '1px solid #e5e7eb',
                                        padding: '8px'
                                    }
                                }).inject(Row);

                                new Element('td', {
                                    text: (entry.size || 0) + ' B',
                                    styles: {
                                        borderBottom: '1px solid #e5e7eb',
                                        padding: '8px'
                                    }
                                }).inject(Row);

                                new Element('td', {
                                    text: entry.mtime || '-',
                                    styles: {
                                        borderBottom: '1px solid #e5e7eb',
                                        padding: '8px'
                                    }
                                }).inject(Row);

                                const ActionCell = new Element('td', {
                                    styles: {
                                        borderBottom: '1px solid #e5e7eb',
                                        padding: '8px',
                                        textAlign: 'right'
                                    }
                                }).inject(Row);

                                new Element('button', {
                                    html: '<span class="fa fa-download" style="margin-right:6px;"></span>' +
                                        QUILocale.get(lg, 'archive.download'),
                                    'class': 'btn btn-secondary',
                                    events: {
                                        click: () => {
                                            const file = String(entry.file || '');

                                            if (!file) {
                                                return;
                                            }

                                            DownloadManager.download(
                                                'package_quiqqer_mail-journal_ajax_backend_downloadArchive',
                                                {
                                                    'package': 'quiqqer/mail-journal',
                                                    file: file
                                                }
                                            );
                                        }
                                    }
                                }).inject(ActionCell);
                            });
                        }, {
                            'package': 'quiqqer/mail-journal'
                        });
                    }
                }
            }).open();
        },

        $openFilterDialog: function () {
            new QUIConfirm({
                title: QUILocale.get(lg, 'filter.dialog.title'),
                icon: 'fa fa-sliders',
                autoclose: false,
                maxWidth: 520,
                maxHeight: 420,
                ok_button: {
                    text: QUILocale.get(lg, 'filter.dialog.apply'),
                    textimage: 'fa fa-check'
                },
                events: {
                    onOpen: (Win) => {
                        const Content = Win.getContent();
                        Content.setStyles({
                            padding: 15
                        });
                        Content.set('html', '');

                        const Row = (label, Control) => {
                            const Wrap = new Element('div', {
                                styles: {
                                    marginBottom: 12
                                }
                            }).inject(Content);

                            new Element('label', {
                                text: label,
                                styles: {
                                    display: 'block',
                                    marginBottom: 4,
                                    fontWeight: 600
                                }
                            }).inject(Wrap);

                            Control.setStyle('width', '100%');
                            Control.inject(Wrap);
                        };

                        const DateFrom = new Element('input', {
                            type: 'date',
                            value: this.$FilterValues.dateFrom
                        });

                        const DateTo = new Element('input', {
                            type: 'date',
                            value: this.$FilterValues.dateTo
                        });

                        const HasAttachments = new Element('select');
                        new Element('option', {
                            value: '',
                            text: QUILocale.get(lg, 'filter.attachments.any')
                        }).inject(HasAttachments);
                        new Element('option', {
                            value: '1',
                            text: QUILocale.get(lg, 'filter.attachments.with')
                        }).inject(HasAttachments);
                        new Element('option', {
                            value: '0',
                            text: QUILocale.get(lg, 'filter.attachments.without')
                        }).inject(HasAttachments);
                        HasAttachments.value = this.$FilterValues.hasAttachments;

                        const Archived = new Element('select');
                        new Element('option', {
                            value: '',
                            text: QUILocale.get(lg, 'filter.archived.any')
                        }).inject(Archived);
                        new Element('option', {
                            value: '0',
                            text: QUILocale.get(lg, 'filter.archived.no')
                        }).inject(Archived);
                        new Element('option', {
                            value: '1',
                            text: QUILocale.get(lg, 'filter.archived.yes')
                        }).inject(Archived);
                        Archived.value = this.$FilterValues.archived;

                        Row(QUILocale.get(lg, 'filter.date_from'), DateFrom);
                        Row(QUILocale.get(lg, 'filter.date_to'), DateTo);
                        Row(QUILocale.get(lg, 'filter.attachments.label'), HasAttachments);
                        Row(QUILocale.get(lg, 'filter.archived.label'), Archived);

                        new Element('button', {
                            'class': 'btn btn-secondary',
                            text: QUILocale.get(lg, 'filter.dialog.reset'),
                            styles: {
                                marginTop: 4
                            },
                            events: {
                                click: () => {
                                    DateFrom.value = '';
                                    DateTo.value = '';
                                    HasAttachments.value = '';
                                    Archived.value = '';
                                }
                            }
                        }).inject(Content);

                        Win.$FilterForm = {
                            DateFrom: DateFrom,
                            DateTo: DateTo,
                            HasAttachments: HasAttachments,
                            Archived: Archived
                        };
                    },
                    onSubmit: (Win) => {
                        const F = Win.$FilterForm;

                        if (!F) {
                            Win.close();
                            return;
                        }

                        this.$FilterValues = {
                            dateFrom: F.DateFrom.value || '',
                            dateTo: F.DateTo.value || '',
                            hasAttachments: F.HasAttachments.value || '',
                            archived: F.Archived.value || ''
                        };

                        this.$onFilterChange();
                        Win.close();
                    }
                }
            }).open();
        },

        $onGridDblClick: function () {
            const selected = this.$Grid ? this.$Grid.getSelectedData() : [];

            if (!selected || !selected.length || !selected[0].id) {
                return;
            }

            const row = selected[0];
            const Panel = new MailPanel({
                mailId: row.id,
                '#id': 'mail-journal-mail-' + row.id
            });

            PanelUtils.openPanelInTasks(Panel);
        },

        $onResize: function () {
            if (!this.$Grid || !this.$Container || !this.$GridContainer) {
                return;
            }

            const content = this.getContent();
            const size = content.getSize();

            const paddingLeft = parseInt(content.getStyle('padding-left'), 10) || 0;
            const paddingRight = parseInt(content.getStyle('padding-right'), 10) || 0;
            const paddingTop = parseInt(content.getStyle('padding-top'), 10) || 0;
            const paddingBottom = parseInt(content.getStyle('padding-bottom'), 10) || 0;

            const width = Math.max(0, size.x - paddingLeft - paddingRight);
            const height = Math.max(0, size.y - paddingTop - paddingBottom);

            this.$Container.setStyle('width', width);
            this.$Container.setStyle('height', height);
            this.$GridContainer.setStyle('width', width);
            this.$GridContainer.setStyle('height', height);
            this.$Grid.setWidth(width);
            this.$Grid.setHeight(height);
        }
    });
});
