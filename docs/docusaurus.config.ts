import {themes as prismThemes} from 'prism-react-renderer';
import type {Config} from '@docusaurus/types';

const config: Config = {
  title: 'Jethro PMM',
  tagline: 'Pastoral Ministry Manager — Church Management Software',
  favicon: 'img/favicon.ico',

  future: { v4: true },

  url: 'https://jefft.github.io',
  baseUrl: '/jethro-pmm/',

  organizationName: 'jefft',
  projectName: 'jethro-pmm',
  trailingSlash: 'false',

  onBrokenLinks: 'throw',


  plugins: [require.resolve('docusaurus-plugin-image-zoom'), require.resolve('./plugins/validate-settings-sync'), require.resolve('./plugins/validate-settings-documented'), require.resolve('./plugins/preserve-symlinks')],

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  markdown: {
    mermaid: true,
  },
  themes: ['@docusaurus/theme-mermaid'],
  presets: [
    [
      'classic',
      {
        docs: {
          sidebarPath: './sidebars.ts',
          editUrl: 'https://github.com/jethro-pmm/jethro/edit/main/docs/',
          lastVersion: 'current',
          exclude: ['**/jethrosettings.json'],
          versions: {
            current: {
              label: '2.40.0-dev',
              path: '2.40.0-dev',
              banner: 'unreleased',
            },
          },
        },
        blog: false,
        theme: {
          customCss: './src/css/custom.css',
        },
      },
    ],
  ],

  themeConfig: {
    zoom: {
      selector: '.markdown :not(em) img',
    },
    colorMode: {
      respectPrefersColorScheme: true,
    },
    navbar: {
      title: 'Church Management',
      logo: {
        alt: 'Jethro PMM Logo',
        src: 'img/jethro-black.png',
      },
      items: [
        { type: 'doc', docId: 'overview', label: 'Overview', position: 'left' },
        { type: 'doc', docId: 'installation/index', position: 'left', label: 'Install', },
        { type: 'docSidebar', sidebarId: 'userManual', position: 'left', label: 'User Manual', },
        { type: 'docSidebar', sidebarId: 'administration', position: 'left', label: 'Administration', },
        { type: 'doc', docId: 'changelog/index', label: "What's New", position: 'left' },
        { type: 'docSidebar', sidebarId: 'developer', position: 'left', label: 'Developer', },
        { href: 'https://easyjethro.com.au', label: 'Easy Jethro', position: 'left' },
        { type: 'docsVersionDropdown', position: 'right' },
      ],
    },
    footer: {
      style: 'dark',
      links: [],
      copyright: `Copyright © ${new Date().getFullYear()} Jethro PMM. Built with Docusaurus.`,
    },
    prism: {
      theme: prismThemes.github,
      darkTheme: prismThemes.dracula,
      additionalLanguages: ['php', 'bash', 'json', 'sql'],
    },
  },
};

export default config;
