import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel XTDB',
  description: 'An XTDB 2 database driver for Laravel: Eloquent, the query builder and migrations, with bitemporal queries.',
  base: '/laravel-xtdb2/',

  themeConfig: {
    nav: [
      { text: 'Docs', link: '/docs/installation' },
      { text: 'Bitemporal', link: '/docs/bitemporal' },
      {
        text: 'Resources',
        items: [
          { text: 'GitHub', link: 'https://github.com/vuthaihoc/laravel-xtdb2' },
          { text: 'Packagist', link: 'https://packagist.org/packages/vuthaihoc/laravel-xtdb2' },
          { text: 'XTDB', link: 'https://docs.xtdb.com/' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/docs/installation' },
          { text: 'Models', link: '/docs/models' },
          { text: 'Migrations', link: '/docs/migrations' },
        ],
      },
      {
        text: 'Bitemporal Data',
        items: [
          { text: 'Concepts and use cases', link: '/docs/bitemporal' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Values and types', link: '/docs/values-and-types' },
          { text: 'Transactions', link: '/docs/transactions' },
          { text: 'laravel-db-portable', link: '/docs/laravel-db-portable' },
          { text: 'Limits', link: '/docs/limits' },
          { text: 'Testing', link: '/docs/testing' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/vuthaihoc/laravel-xtdb2' },
    ],

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/vuthaihoc/laravel-xtdb2/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
