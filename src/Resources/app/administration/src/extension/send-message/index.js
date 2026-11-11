import template from './send-message-modal.html.twig';

const actionName = 'action.inocean.messaging.send';
const modalName = 'ino-messaging-flow-send-message-modal';
const globalScope = '00000000000000000000000000000000';
const { Criteria } = Shopware.Data;

Shopware.Component.override('sw-flow-sequence-action', {
    computed: {
        modalName() {
            return this.selectedAction === actionName ? modalName : this.$super('modalName');
        },
    },

    methods: {
        getActionTitle(name) {
            if (name === actionName) {
                return {
                    value: actionName,
                    label: this.$t('ino-messaging.flow.title'),
                    icon: 'regular-comment',
                    group: 'customer',
                };
            }

            return this.$super('getActionTitle', name);
        },

        getActionDescriptions(sequence) {
            if (sequence.actionName === actionName) {
                return this.$t('ino-messaging.flow.recipient');
            }

            return this.$super('getActionDescriptions', sequence);
        },
    },
});

Shopware.Component.register(modalName, {
    template,

    emits: ['modal-close', 'process-finish'],

    props: {
        sequence: { type: Object, required: true },
        action: { type: String, default: null },
    },

    data() {
        return { templateId: this.sequence.config?.templateId ?? null };
    },

    computed: {
        templateCriteria() {
            return new Criteria().addFilter(Criteria.equals('active', true)).addSorting(Criteria.sort('code'));
        },
    },

    methods: {
        templateLabel(template) {
            const scope = template.scopeId === globalScope ? this.$t('ino-messaging.list.global') : template.scopeId;

            return `${template.code} (${scope})`;
        },

        saveAction() {
            if (this.templateId) {
                this.$emit('process-finish', { ...this.sequence, config: { templateId: this.templateId } });
            }
        },
    },
});
