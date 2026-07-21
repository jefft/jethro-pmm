import type {SidebarsConfig} from '@docusaurus/plugin-content-docs';

const sidebars: SidebarsConfig = {
  overview: [
    'overview',
  ],

  installation: [
    {
      type: 'category',
      label: 'Installation',
      link: { type: 'doc', id: 'installation/index' },
      items: [
        'installation/index',
      ],
    },
  ],

  administration: [
    {
      type: 'category',
      label: 'Administration',
      link: { type: 'doc', id: 'administration/config/index' },
      items: [
        'administration/congregations',
        'administration/user-accounts',
        'administration/permissions',
        'administration/note-templates',
        'administration/action-plans',
        {
          type: 'category',
          label: 'System Configuration',
          link: { type: 'doc', id: 'administration/config/index' },
          items: [
            'administration/config/sms',
          ],
        },
        'administration/import',
      ],
    },
  ],

  userManual: [
    {
      type: 'category',
      label: 'User Manual',
      link: { type: 'doc', id: 'user-manual/getting-started' },
      items: [
        'user-manual/getting-started',
        'user-manual/sms',
      ],
    },
  ],

  whatsNew: [
    'changelog/index',
  ],


  developer: [
    {
      type: 'category',
      label: 'Developer',
      items: [
        'developer/DEVELOPMENT_DEVBOX',
        'developer/developer_tips',
        'contributing',
        'developer/reference/view-menu-convention',
        'contributing',
        'developer/DEVELOPMENT_FUNCTESTS',
        {
          type: 'category',
          label: 'SMS Reference',
          items: [
            'developer/reference/sms/smsarchitecture',
            'developer/reference/sms/send-pipeline',
            'developer/reference/sms/providers',
            'developer/reference/sms/provider-abstraction',
            'developer/reference/sms/configuration',
            'developer/reference/sms/database',
            'developer/reference/sms/file-layout',
            'developer/reference/sms/status-codes',
            'developer/reference/sms/token-expansion',
            'developer/reference/sms/character-counting',
            'developer/reference/sms/delivery-tracking',
            'developer/reference/sms/SMS_DATASTAR',
            'developer/reference/sms/history-sync',
            'developer/reference/sms/design-decisions',
            'developer/reference/sms/api-reference',
          ],
        },
      ],
    },
  ],

};

export default sidebars;
