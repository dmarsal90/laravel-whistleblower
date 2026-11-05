# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Symfony bundle (Doctrine DBAL storage, libsodium encryption, security attribute, rate limiter, console commands).
- Report lifecycle with legal deadlines, anonymous tracking codes and an audit trail.
- Daily commands for deadline alerts and data retention.
- JSON API for the public form and for case management.

### Changed

- Renamed to `dmarsal90/whistleblower`. The rules now live in a framework-free core; Laravel is an adapter under `Whistleblower\Laravel`.
- Requires Laravel 12 or 13 (Laravel 11 is out of support).
