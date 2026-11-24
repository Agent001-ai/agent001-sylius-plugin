# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] – 2026-11-24

### Added

- Connecting the store with an Agent001 account from the admin panel, without manual API keys.
- Agent assignment and taxon filter per sales channel.
- Full catalog synchronization per channel with progress in the admin panel, the `agent001:sync` console command and a daily `--due` schedule, processed through Symfony Messenger.
- Indexing status followed by the worker, so the widget appears without an admin visit.
- Real-time product synchronization detected on Doctrine flush.
- Storefront chat widget rendered through Twig Hooks.
- Order status lookup API at `/agent001/v1/orders/{number}` with rate limiting and CORS.
- English and Polish translations.

[1.0.0]: https://github.com/Agent001-ai/agent001-sylius-plugin/releases/tag/1.0.0
