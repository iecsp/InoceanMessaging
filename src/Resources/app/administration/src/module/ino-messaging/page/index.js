import template from './templates.html.twig';
import './templates.scss';

const { Criteria } = Shopware.Data;
const GLOBAL = '00000000000000000000000000000000';
const MEMBER_VARIABLES = ['customerFirstName', 'customerLastName', 'customerFullName', 'customerNumber', 'customerCompany', 'customerLanguage', 'unsubscribeUrl'];

Shopware.Component.register('ino-messaging-templates', {
    template,
    inject: ['repositoryFactory', 'loginService', 'acl'],
    mixins: [Shopware.Mixin.getByName('notification')],
    data() {
        return {
            loading: false, saving: false, error: '', records: null, page: 1, total: 0, term: '',
            languages: [], languageId: Shopware.Defaults.systemLanguageId, translations: {},
            model: null, variableText: '', previewBody: '', previewMeta: null,
            previewScope: null, previewCustomer: null, previewVariables: '{"businessName":"Inocean","reference":"123"}',
            deleteTarget: null,
        };
    },
    computed: {
        repository() { return this.repositoryFactory.create('ino_sms_template'); },
        editing() { return this.$route.name !== 'ino.messaging.index'; },
        creating() { return this.$route.name === 'ino.messaging.create'; },
        canSave() { return this.creating ? this.acl.can('ino_messaging.creator') : this.acl.can('ino_messaging.editor'); },
        scopeSelection: {
            get() { return this.model?.scopeId === GLOBAL ? null : this.model?.scopeId; },
            set(value) { this.model.scopeId = value || GLOBAL; },
        },
        customerCriteria() { const criteria = new Criteria(1,25); if (this.previewScope) criteria.addFilter(Criteria.equals('salesChannelId',this.previewScope)); return criteria; },
        body: {
            get() { return this.translations[this.languageId] || ''; },
            set(value) { this.translations[this.languageId] = value; this.previewBody = ''; },
        },
        languageOptions() { return this.languages.map(l => ({ value: l.id, label: `${l.name} (${l.locale?.code || ''})` })); },
        memberVariables() { return MEMBER_VARIABLES; },
        headerSubtitle() { return this.$t(`ino-messaging.general.${this.editing ? (this.creating ? 'newTemplate' : 'editTemplate') : 'templates'}`); },
        bodyPlaceholder() { return this.$t('ino-messaging.content.placeholder', { name: '{{ customerFirstName }}' }); },
        purposeOptions() { return ['transactional', 'marketing'].map(value => ({ value, label: this.$t(`ino-messaging.purpose.${value}`) })); },
        columns() {
            return [
                { property: 'code', label: this.$t('ino-messaging.list.code'), routerLink: 'ino.messaging.detail', primary: true },
                { property: 'purpose', label: this.$t('ino-messaging.list.purpose') },
                { property: 'scopeId', label: this.$t('ino-messaging.list.scope') },
                { property: 'active', label: this.$t('ino-messaging.list.active'), align: 'center' },
            ];
        },
    },
    watch: { '$route.fullPath': 'load' },
    created() { this.load(); },
    methods: {
        async load() {
            this.loading = true; this.error = ''; this.previewBody = ''; this.previewMeta = null;
            this.previewCustomer = null; this.translations = {}; this.languageId = Shopware.Defaults.systemLanguageId;
            try {
                if (!this.editing) { await this.loadList(); return; }
                const lc = new Criteria(1, 100); lc.addAssociation('locale'); lc.addSorting(Criteria.sort('name', 'ASC'));
                const languages = await this.repositoryFactory.create('language').search(lc, Shopware.Context.api);
                this.languages = [...languages];
                if (this.creating) {
                    this.model = { id: Shopware.Utils.createId(), code: '', scopeId: GLOBAL, purpose: 'transactional', active: true, allowedVariables: ['businessName', 'reference'] };
                } else {
                    const criteria = new Criteria(); criteria.addAssociation('translations');
                    this.model = await this.repository.get(this.$route.params.id, Shopware.Context.api, criteria);
                    this.model.translations?.forEach(t => { this.translations[t.languageId] = t.body || ''; });
                    if (!this.translations[Shopware.Defaults.systemLanguageId] && this.model.body) this.translations[Shopware.Defaults.systemLanguageId] = this.model.body;
                }
                this.variableText = (this.model.allowedVariables || []).join(', ');
                this.previewScope = this.model.scopeId === GLOBAL ? null : this.model.scopeId;
            } catch (e) { this.showError(e); }
            finally { this.loading = false; }
        },
        async loadList() {
            const c = new Criteria(this.page, 25); c.addSorting(Criteria.sort('code', 'ASC')); if (this.term) c.setTerm(this.term);
            this.records = await this.repository.search(c, Shopware.Context.api); this.total = this.records.total;
        },
        async search() { this.page = 1; await this.load(); },
        async pageChanged({ page }) { this.page = page; await this.load(); },
        async save() {
            this.saving = true; this.error = '';
            try {
                if (!/^[a-z][a-z0-9_]{0,99}$/.test(this.model.code)) throw this.validationError('invalidCode');
                const allowed = this.variableText.split(',').map(s => s.trim()).filter(Boolean);
                if (allowed.some(s => !/^[a-zA-Z][a-zA-Z0-9_]*$/.test(s))) throw this.validationError('invalidVariable');
                if (!this.translations[Shopware.Defaults.systemLanguageId]?.trim()) throw this.validationError('missingDefaultBody');
                const translations = Object.fromEntries(Object.entries(this.translations).filter(([,body]) => body.trim()).map(([id,body]) => [id, { body }]));
                const payload = { id: this.model.id, code: this.model.code, scopeId: this.model.scopeId || GLOBAL, purpose: this.model.purpose, active: this.model.active, allowedVariables: [...new Set(allowed)], translations };
                await Shopware.Service('syncService').sync({ 'ino-sms-template-save': { entity: 'ino_sms_template', action: 'upsert', payload: [payload] } });
                this.createNotificationSuccess({ message: this.$t('ino-messaging.notifications.saved') });
                if (this.creating) await this.$router.push({ name: 'ino.messaging.detail', params: { id: this.model.id } });
                else await this.load();
            } catch (e) { this.showError(e); }
            finally { this.saving = false; }
        },
        async remove() {
            this.saving = true;
            try { await this.repository.delete(this.deleteTarget.id, Shopware.Context.api); this.deleteTarget = null; await this.loadList(); this.createNotificationSuccess({ message: this.$t('ino-messaging.notifications.deleted') }); }
            catch (e) { this.showError(e); }
            finally { this.saving = false; }
        },
        async preview() {
            this.error = ''; this.previewBody = ''; this.previewMeta = null;
            try {
                const payload = { body: this.body, allowedVariables: this.variableText.split(',').map(v => v.trim()).filter(Boolean), variables: this.parsePreviewVariables() };
                if (this.previewCustomer) { if (!this.previewScope) throw this.validationError('missingPreviewScope'); payload.customerId = this.previewCustomer; payload.scopeId = this.previewScope; }
                const { data } = await Shopware.Application.getContainer('init').httpClient.post('_action/inocean/messaging/preview', payload, { headers: { Authorization: `Bearer ${this.loginService.getToken()}`, Accept: 'application/json' } });
                this.previewBody = data.body; this.previewMeta = data;
            } catch (e) { this.showError(e); }
        },
        addVariable(name) {
            const variables = this.variableText.split(',').map(v => v.trim()).filter(Boolean);
            if (!variables.includes(name)) this.variableText = [...variables, name].join(', ');
            this.body += `{{ ${name} }}`;
        },
        parsePreviewVariables() {
            try {
                const value = JSON.parse(this.previewVariables);
                if (!value || typeof value !== 'object' || Array.isArray(value)) throw new SyntaxError();
                return value;
            } catch { throw this.validationError('invalidJson'); }
        },
        validationError(key) { return Object.assign(new Error(this.$t(`ino-messaging.errors.${key}`)), { inoMessagingTranslationKey: key }); },
        showError(e) {
            const statusKeys = { 400: 'invalidRequest', 401: 'unauthorized', 403: 'forbidden', 404: 'notFound', 409: 'conflict', 429: 'rateLimited', 500: 'serverError', 502: 'serverError', 503: 'serverError' };
            const key = e?.inoMessagingTranslationKey || statusKeys[e?.response?.status] || 'requestFailed';
            this.error = this.$t(`ino-messaging.errors.${key}`);
            this.createNotificationError({ message: this.error });
        },
    },
});
