define('package/quiqqer/mail-journal/bin/javascript/backend/controls/Panel', [
    'qui/controls/desktop/Panel',
    'controls/grid/Grid',
    'Ajax',
    'Locale'
], function (QUIPanel, Grid, QUIAjax, QUILocale) {
    'use strict';

    const lg = 'quiqqer/mail-journal';

    return new Class({

        Extends: QUIPanel,
        Type: 'package/quiqqer/mail-journal/bin/javascript/backend/controls/Panel',

        Binds: [
            '$onCreate',
            '$onInject',
            '$onResize',
            '$gridRefresh'
        ],

        initialize: function (options) {
            this.setAttributes({
                title: QUILocale.get(lg, 'panel.title'),
                icon: 'fa fa-envelope'
            });

            this.parent(options);

            this.$Container = null;
            this.$Grid = null;

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

            this.$Grid = new Grid(this.$Container, {
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
                pagination: true,
                serverSort: true,
                sortOn: 'send_date',
                sortBy: 'DESC',
                perPage: 20,
                onrefresh: this.$gridRefresh
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
                this.Loader.hide();
            }, {
                'package': 'quiqqer/mail-journal',
                params: JSON.encode({
                    perPage: grid.getAttribute('perPage') || 20,
                    page: grid.getAttribute('page') || 1,
                    sortOn: grid.getAttribute('sortOn') || 'send_date',
                    sortBy: grid.getAttribute('sortBy') || 'DESC'
                })
            });
        },

        $onResize: function () {
            if (!this.$Grid || !this.$Container) {
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
            this.$Grid.setWidth(width);
            this.$Grid.setHeight(height);
        }
    });
});
