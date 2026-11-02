import './page';
import enGB from '../../snippet/en-GB.json';
import zhCN from '../../snippet/zh-CN.json';
import zhTW from '../../snippet/zh-TW.json';

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions', parent: 'settings', key: 'ino_messaging',
    roles: {
        viewer: { privileges: ['ino_sms_template:read', 'inocean_messaging:read', 'language:read', 'locale:read', 'sales_channel:read'], dependencies: [] },
        editor: { privileges: ['ino_sms_template:update'], dependencies: ['ino_messaging.viewer'] },
        creator: { privileges: ['ino_sms_template:create'], dependencies: ['ino_messaging.viewer', 'ino_messaging.editor'] },
        deleter: { privileges: ['ino_sms_template:delete'], dependencies: ['ino_messaging.viewer'] },
    },
});

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'additional_permissions', parent: null, key: 'ino_messaging',
    roles: { manageSubscriptions: { privileges: ['inocean_messaging:manage', 'customer:read', 'customer:update', 'customer_address:read'], dependencies: ['ino_messaging.viewer'] } },
});

Shopware.Module.register('ino-messaging', {
    type: 'plugin', name: 'ino-messaging', title: 'ino-messaging.general.title', description: 'ino-messaging.general.description',
    snippets: { 'en-GB': enGB, 'en-US': enGB, 'zh-CN': zhCN, 'zh-TW': zhTW, 'zh-HK': zhTW },
    color: '#155eef', icon: 'regular-comment', entity: 'ino_sms_template',
    routes: {
        index: { component: 'ino-messaging-templates', path: 'index', meta: { privilege: 'ino_messaging.viewer' } },
        create: { component: 'ino-messaging-templates', path: 'create', meta: { parentPath: 'ino.messaging.index', privilege: 'ino_messaging.creator' } },
        detail: { component: 'ino-messaging-templates', path: 'detail/:id', meta: { parentPath: 'ino.messaging.index', privilege: 'ino_messaging.viewer' } },
    },
    navigation: [{ id: 'ino-messaging', label: 'ino-messaging.general.title', color: '#155eef', path: 'ino.messaging.index', icon: 'regular-comment', parent: 'sw-marketing', position: 90, privilege: 'ino_messaging.viewer' }],
});
